<?php

namespace App\Modules\MetaIntegration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MetaIntegration\Jobs\ProcessMetaCommentWebhookEvent;
use App\Modules\MetaIntegration\Jobs\ProcessMetaLeadWebhookEvent;
use App\Modules\MetaIntegration\Jobs\ProcessMetaMessageWebhookEvent;
use App\Modules\MetaIntegration\Jobs\ProcessMetaWhatsAppWebhookEvent;
use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\MetaIntegration\Models\MetaLeadFormMapping;
use App\Modules\MetaIntegration\Models\MetaPage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MetaLeadWebhookController extends Controller
{
    public function verify(Request $request)
    {
        // PHP auto-converts dots in query-string keys to underscores, so Meta's
        // "hub.mode"/"hub.verify_token"/"hub.challenge" params arrive as hub_mode/etc.
        $providedToken = (string) $request->query('hub_verify_token');

        if ($request->query('hub_mode') === 'subscribe' && $this->isKnownVerifyToken($providedToken)) {
            return response((string) $request->query('hub_challenge'), 200);
        }

        return response('Invalid verify token', 403);
    }

    /**
     * Each tenant runs their own Meta App with its own webhook subscription, so
     * the verify token is the per-company one generated when they saved their
     * App ID and secret.
     */
    private function isKnownVerifyToken(string $providedToken): bool
    {
        if ($providedToken === '') {
            return false;
        }

        // One indexed lookup against the per-company tokens. This endpoint is
        // unauthenticated, so it must not decrypt every connection per call,
        // and there is no platform-wide token to accept.
        return MetaConnection::where('webhook_verify_token_hash', hash('sha256', $providedToken))->exists();
    }

    public function receive(Request $request)
    {
        $signature = $request->header('X-Hub-Signature-256', '');

        if (! $this->hasValidSignature($request->getContent(), $signature)) {
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                match ($change['field'] ?? null) {
                    'leadgen' => $this->dispatchLeadgen($change['value'] ?? []),
                    'messages' => $this->dispatchWhatsAppMessages($change['value'] ?? []),
                    'feed' => $this->dispatchFeedComment($change['value'] ?? [], $entry['id'] ?? null),
                    'comments' => $this->dispatchInstagramComment($change['value'] ?? [], $entry['id'] ?? null),
                    default => null,
                };
            }

            foreach ($entry['messaging'] ?? [] as $messageEvent) {
                $this->dispatchAccountMessage($entry['id'] ?? null, $messageEvent);
            }
        }

        return response()->json(['status' => 'ok'], Response::HTTP_OK);
    }

    private function dispatchLeadgen(array $value): void
    {
        if (empty($value['leadgen_id']) || empty($value['page_id']) || empty($value['form_id'])) {
            return;
        }

        ProcessMetaLeadWebhookEvent::dispatch(
            $value['page_id'],
            $value['form_id'],
            $value['leadgen_id'],
        )->onQueue('meta-events');
    }

    /**
     * Page feed events cover posts, likes and comments; only comments from
     * someone else are worth a human's attention.
     */
    private function dispatchFeedComment(array $value, ?string $entryId): void
    {
        $isComment = ($value['item'] ?? null) === 'comment' && ($value['verb'] ?? null) === 'add';
        $pageId = $entryId ?: ($value['page_id'] ?? null);

        if (! $isComment || ! $pageId || empty($value['comment_id'])) {
            return;
        }

        ProcessMetaCommentWebhookEvent::dispatch((string) $pageId, $value)->onQueue('meta-events');
    }

    private function dispatchInstagramComment(array $value, ?string $entryId): void
    {
        if (! $entryId || empty($value['id'])) {
            return;
        }

        ProcessMetaCommentWebhookEvent::dispatch((string) $entryId, $value, 'instagram')
            ->onQueue('meta-events');
    }

    private function dispatchAccountMessage(?string $accountId, array $event): void
    {
        $message = $event['message'] ?? [];

        if (! $accountId || empty($message['mid']) || ! empty($message['is_echo']) || empty($event['sender']['id'])) {
            return;
        }

        ProcessMetaMessageWebhookEvent::dispatch((string) $accountId, $event)->onQueue('meta-events');
    }

    private function dispatchWhatsAppMessages(array $value): void
    {
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
        if (! $phoneNumberId) {
            return;
        }

        $profileName = $value['contacts'][0]['profile']['name'] ?? null;

        foreach ($value['messages'] ?? [] as $message) {
            if (empty($message['from']) || empty($message['id'])) {
                continue;
            }

            ProcessMetaWhatsAppWebhookEvent::dispatch(
                $phoneNumberId,
                $message['from'],
                $profileName,
                $message['text']['body'] ?? null,
                $message['id'],
            )->onQueue('meta-events');
        }
    }

    /**
     * Every tenant runs their own Meta App, so the payload is verified against
     * that company's secret and nothing else: an unresolvable payload is
     * rejected rather than checked against a shared key.
     */
    private function hasValidSignature(string $rawBody, string $signature): bool
    {
        $secret = $this->appSecretForPayload($rawBody);

        if (! $secret || $signature === '') {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    private function appSecretForPayload(string $rawBody): ?string
    {
        $payload = json_decode($rawBody, true) ?? [];
        foreach ($payload['entry'] ?? [] as $entry) {
            // Feed events identify the page through the entry id rather than
            // a page_id inside the change value.
            if (! empty($entry['id'])) {
                $page = MetaPage::with('connection')
                    ->where(function ($query) use ($entry) {
                        $query->where('page_id', $entry['id'])
                            ->orWhere('instagram_account_id', $entry['id']);
                    })
                    ->first();
                if ($page?->connection?->app_secret) {
                    return $page->connection->app_secret;
                }
            }

            foreach ($entry['changes'] ?? [] as $change) {
                if ($pageId = $change['value']['page_id'] ?? null) {
                    $mapping = MetaLeadFormMapping::with('connection')->where('page_id', $pageId)->first();
                    if ($mapping?->connection?->app_secret) {
                        return $mapping->connection->app_secret;
                    }
                }

                // WhatsApp payloads carry no page_id; the owning company is
                // identified by the receiving phone number instead.
                if ($phoneNumberId = $change['value']['metadata']['phone_number_id'] ?? null) {
                    $connection = MetaConnection::where('whatsapp_phone_number_id', $phoneNumberId)->first();
                    if ($connection?->app_secret) {
                        return $connection->app_secret;
                    }
                }
            }
        }

        return null;
    }
}
