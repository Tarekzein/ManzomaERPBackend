<?php

namespace App\Modules\Platform\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\CRM\Models\CRMTask;
use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\MetaIntegration\Models\MetaPage;
use App\Modules\MetaIntegration\Services\MetaGraphClient;
use App\Modules\Platform\Models\SocialInteraction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The social inbox: comments and messages from every connected platform in one
 * place, each one turnable into a CRM task so support work does not live in a
 * browser tab nobody owns.
 *
 * There is no separate ticketing module in this system, so a "ticket" is a CRM
 * task linked to the contact — the same object the follow-up reminders already
 * run on.
 */
class SocialInboxService
{
    /**
     * Record an interaction. Safe to call repeatedly: platforms redeliver, so
     * the platform's own id is the dedupe key.
     */
    public function record(int $companyId, array $attributes): ?SocialInteraction
    {
        if (empty($attributes['external_id']) || empty($attributes['platform'])) {
            return null;
        }

        $existing = SocialInteraction::where('company_id', $companyId)
            ->where('platform', $attributes['platform'])
            ->where('external_id', $attributes['external_id'])
            ->first();

        if ($existing) {
            return $existing;
        }

        $attributes['company_id'] = $companyId;
        $attributes['status'] ??= 'new';
        $attributes['posted_at'] ??= now();
        $attributes['crm_contact_id'] ??= $this->matchContact($companyId, $attributes)?->id;

        try {
            return SocialInteraction::create($attributes);
        } catch (UniqueConstraintViolationException) {
            // Concurrent redelivery won the race.
            return SocialInteraction::where('company_id', $companyId)
                ->where('platform', $attributes['platform'])
                ->where('external_id', $attributes['external_id'])
                ->first();
        }
    }

    /**
     * Pull recent comments for a page's posts into the inbox. Meta only pushes
     * comment webhooks for subscribed pages, so this backfills and catches up.
     *
     * @return int the number of new interactions recorded
     */
    public function importPageComments(MetaPage $page, int $postLimit = 10): int
    {
        $token = $page->access_token;

        if (! $token) {
            throw ValidationException::withMessages([
                'page' => ['Re-sync this Page from Meta before importing comments.'],
            ]);
        }

        $client = MetaGraphClient::withToken($token);
        $imported = 0;

        $posts = $client->get("{$page->page_id}/posts", [
            'fields' => 'id,permalink_url',
            'limit' => $postLimit,
        ])['data'] ?? [];

        foreach ($posts as $post) {
            $comments = $client->get("{$post['id']}/comments", [
                'fields' => 'id,message,created_time,from{id,name},permalink_url',
                'limit' => 50,
            ])['data'] ?? [];

            foreach ($comments as $comment) {
                $recorded = $this->record($page->company_id, [
                    'platform' => 'facebook',
                    'type' => 'comment',
                    'external_id' => $comment['id'],
                    'parent_external_id' => $post['id'],
                    'page_id' => $page->page_id,
                    'author_external_id' => $comment['from']['id'] ?? null,
                    'author_name' => $comment['from']['name'] ?? null,
                    'message' => $comment['message'] ?? null,
                    'permalink' => $comment['permalink_url'] ?? ($post['permalink_url'] ?? null),
                    'posted_at' => isset($comment['created_time']) ? now()->parse($comment['created_time']) : now(),
                    'payload' => $comment,
                ]);

                if ($recorded?->wasRecentlyCreated) {
                    $imported++;
                }
            }
        }

        Log::info('[social] page comments imported', [
            'company_id' => $page->company_id,
            'page_id' => $page->page_id,
            'imported' => $imported,
        ]);

        return $imported;
    }

    /**
     * Turn an interaction into assigned work. The contact is created when the
     * author is not yet known, so the conversation has somewhere to live.
     */
    public function convertToTask(SocialInteraction $interaction, User $actor, ?int $assigneeId = null, ?string $title = null): CRMTask
    {
        if ($interaction->crm_task_id) {
            return CRMTask::where('company_id', $interaction->company_id)
                ->findOrFail($interaction->crm_task_id);
        }

        $contact = $interaction->contact ?: $this->createContactFrom($interaction);

        $task = CRMTask::create([
            'company_id' => $interaction->company_id,
            'contact_id' => $contact?->id,
            'assignee_id' => $assigneeId ?: $actor->id,
            'created_by' => $actor->id,
            'title' => $title ?: $this->defaultTitle($interaction),
            'status' => 'open',
            'priority' => 'normal',
            'due_at' => now()->addDay(),
            'reminder_at' => now()->addHours(4),
            'notes' => $this->taskNotes($interaction),
        ]);

        $interaction->forceFill([
            'crm_task_id' => $task->id,
            'crm_contact_id' => $contact?->id,
            'status' => 'handled',
            'handled_by' => $actor->id,
            'handled_at' => now(),
        ])->save();

        return $task;
    }

    public function markHandled(SocialInteraction $interaction, User $actor, string $status = 'handled'): SocialInteraction
    {
        $interaction->forceFill([
            'status' => $status,
            'handled_by' => $status === 'new' ? null : $actor->id,
            'handled_at' => $status === 'new' ? null : now(),
        ])->save();

        return $interaction->fresh();
    }

    /** Reply to a comment on the platform it came from. */
    public function reply(SocialInteraction $interaction, string $message): array
    {
        if ($interaction->platform === 'whatsapp' && $interaction->type === 'message') {
            return $this->replyToWhatsApp($interaction, $message);
        }

        $page = MetaPage::where('company_id', $interaction->company_id)
            ->where(function ($query) use ($interaction) {
                $query->where('page_id', $interaction->page_id)
                    ->orWhere('instagram_account_id', $interaction->page_id);
            })
            ->firstOrFail();

        abort_unless($page->access_token, 422, 'Re-sync your pages to refresh the page token.');

        $client = MetaGraphClient::withToken($page->access_token);

        if ($interaction->type === 'message' && in_array($interaction->platform, ['facebook', 'instagram'], true)) {
            abort_unless($interaction->author_external_id, 422, 'This message has no reply recipient.');

            $accountId = $interaction->platform === 'instagram'
                ? $page->instagram_account_id
                : $page->page_id;

            return $client->postJson("{$accountId}/messages", [
                'recipient' => ['id' => $interaction->author_external_id],
                'message' => ['text' => $message],
            ]);
        }

        if ($interaction->type !== 'comment' || ! in_array($interaction->platform, ['facebook', 'instagram'], true)) {
            throw ValidationException::withMessages([
                'interaction' => ['Replies are not supported for this interaction type.'],
            ]);
        }

        return $client->post(
            "{$interaction->external_id}/".($interaction->platform === 'instagram' ? 'replies' : 'comments'),
            ['message' => $message]
        );
    }

    private function replyToWhatsApp(SocialInteraction $interaction, string $message): array
    {
        $connection = MetaConnection::where('company_id', $interaction->company_id)
            ->where('whatsapp_phone_number_id', $interaction->page_id)
            ->where('whatsapp_enabled', true)
            ->firstOrFail();

        abort_unless($interaction->author_external_id, 422, 'This message has no reply recipient.');

        return (new MetaGraphClient($connection))->postJson("{$connection->whatsapp_phone_number_id}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $interaction->author_external_id,
            'type' => 'text',
            'text' => ['body' => $message],
        ]);
    }

    /** Match the author to an existing contact where we can. */
    private function matchContact(int $companyId, array $attributes): ?CRMContact
    {
        $authorId = $attributes['author_external_id'] ?? null;
        $name = $attributes['author_name'] ?? null;

        if ($authorId) {
            $bySocialId = CRMContact::where('company_id', $companyId)
                ->where('custom_attributes->social_id', $authorId)
                ->first();

            if ($bySocialId) {
                return $bySocialId;
            }
        }

        // Names are weak evidence, so only an exact match counts.
        return $name
            ? CRMContact::where('company_id', $companyId)->where('name', $name)->first()
            : null;
    }

    private function createContactFrom(SocialInteraction $interaction): ?CRMContact
    {
        if (! $interaction->author_name && ! $interaction->author_external_id) {
            return null;
        }

        // Match again at conversion time: the contact may have been created
        // after the interaction was first recorded.
        $matched = $this->matchContact((int) $interaction->company_id, [
            'author_external_id' => $interaction->author_external_id,
            'author_name' => $interaction->author_name,
        ]);

        if ($matched) {
            return $matched;
        }

        return CRMContact::create([
            'company_id' => $interaction->company_id,
            'name' => $interaction->author_name ?: 'Social contact',
            'type' => 'lead',
            'status' => 'new',
            'currency' => 'EGP',
            'source' => $interaction->platform,
            'custom_attributes' => array_filter([
                'social_id' => $interaction->author_external_id,
                'social_platform' => $interaction->platform,
            ]),
        ]);
    }

    private function defaultTitle(SocialInteraction $interaction): string
    {
        $who = $interaction->author_name ?: 'Someone';
        $what = $interaction->type === 'comment' ? 'commented' : 'sent a message';

        return "{$who} {$what} on ".ucfirst($interaction->platform);
    }

    private function taskNotes(SocialInteraction $interaction): string
    {
        return trim(($interaction->message ?: '(no text)')."\n\n".($interaction->permalink ?: ''));
    }
}
