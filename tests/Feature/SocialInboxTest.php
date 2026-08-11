<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\CRM\Models\CRMTask;
use App\Modules\MetaIntegration\Exceptions\MetaGraphException;
use App\Modules\MetaIntegration\Jobs\ProcessMetaCommentWebhookEvent;
use App\Modules\MetaIntegration\Jobs\ProcessMetaMessageWebhookEvent;
use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\MetaIntegration\Models\MetaPage;
use App\Modules\MetaIntegration\Services\MetaWhatsAppService;
use App\Modules\Platform\Models\SocialInteraction;
use App\Modules\Platform\Services\SocialInboxService;
use App\Modules\Platform\Services\SocialPublishingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SocialInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_page_comment_webhook_is_queued_and_lands_in_the_inbox(): void
    {
        $admin = $this->admin();
        $this->connection($admin);
        $this->page($admin);

        Queue::fake();

        $body = json_encode(['entry' => [[
            'id' => 'page-1',
            'changes' => [[
                'field' => 'feed',
                'value' => [
                    'item' => 'comment',
                    'verb' => 'add',
                    'comment_id' => 'comment-1',
                    'post_id' => 'post-1',
                    'message' => 'Do you deliver to Alexandria?',
                    'from' => ['id' => 'fb-user-1', 'name' => 'Nadia Fouad'],
                    'created_time' => now()->timestamp,
                ],
            ]],
        ]]]);

        $this->postSigned($body, 'secret-a')->assertOk();

        Queue::assertPushedOn('meta-events', ProcessMetaCommentWebhookEvent::class);

        // Run the job for real to prove the ingestion path end to end.
        (new ProcessMetaCommentWebhookEvent('page-1', json_decode($body, true)['entry'][0]['changes'][0]['value']))
            ->handle(app(SocialInboxService::class));

        $interaction = SocialInteraction::where('external_id', 'comment-1')->firstOrFail();
        $this->assertSame($admin->company_id, $interaction->company_id);
        $this->assertSame('facebook', $interaction->platform);
        $this->assertSame('comment', $interaction->type);
        $this->assertSame('Do you deliver to Alexandria?', $interaction->message);
        $this->assertSame('Nadia Fouad', $interaction->author_name);
        $this->assertSame('new', $interaction->status);
    }

    /** Meta redelivers webhooks; the same comment must not appear twice. */
    public function test_a_redelivered_comment_does_not_create_a_second_interaction(): void
    {
        $admin = $this->admin();
        $this->connection($admin);
        $this->page($admin);

        $value = [
            'comment_id' => 'comment-dupe',
            'post_id' => 'post-1',
            'message' => 'Hello',
            'from' => ['id' => 'fb-user-2', 'name' => 'Omar'],
        ];

        foreach (range(1, 3) as $ignored) {
            (new ProcessMetaCommentWebhookEvent('page-1', $value))->handle(app(SocialInboxService::class));
        }

        $this->assertSame(1, SocialInteraction::where('external_id', 'comment-dupe')->count());
    }

    /** A comment on a page we do not have mapped belongs to nobody. */
    public function test_a_comment_on_an_unmapped_page_is_ignored(): void
    {
        $this->admin();

        (new ProcessMetaCommentWebhookEvent('page-nobody-owns', ['comment_id' => 'comment-orphan']))
            ->handle(app(SocialInboxService::class));

        $this->assertSame(0, SocialInteraction::count());
    }

    public function test_the_inbox_only_returns_the_callers_company(): void
    {
        $admin = $this->admin();
        $other = Company::create(['name' => 'Rival Co', 'email' => 'rival@example.com', 'status' => 'active']);

        $this->interaction($admin->company_id, 'mine-1');
        $this->interaction($other->id, 'theirs-1');

        $data = $this->getJson('/api/social/inbox')->assertOk()->json('data.data');

        $this->assertCount(1, $data);
        $this->assertSame('mine-1', $data[0]['external_id']);

        $summary = $this->getJson('/api/social/inbox/summary')->assertOk()->json('data');
        $this->assertSame(1, $summary['open']);
        $this->assertSame(1, $summary['by_platform']['facebook']);
    }

    public function test_an_interaction_from_another_company_cannot_be_touched(): void
    {
        $this->admin();
        $other = Company::create(['name' => 'Rival Co', 'email' => 'rival2@example.com', 'status' => 'active']);
        $theirs = $this->interaction($other->id, 'theirs-2');

        $this->postJson("/api/social/inbox/{$theirs->id}/task")->assertForbidden();
        $this->putJson("/api/social/inbox/{$theirs->id}/status", ['status' => 'handled'])->assertForbidden();
        $this->postJson("/api/social/inbox/{$theirs->id}/reply", ['message' => 'hi'])->assertForbidden();
    }

    public function test_converting_an_interaction_creates_a_task_and_a_contact(): void
    {
        $admin = $this->admin();
        $interaction = $this->interaction($admin->company_id, 'comment-convert');

        $task = $this->postJson("/api/social/inbox/{$interaction->id}/task")
            ->assertCreated()
            ->json('data');

        $created = CRMTask::findOrFail($task['id']);
        $this->assertSame($admin->company_id, $created->company_id);
        $this->assertSame($admin->id, $created->assignee_id);
        $this->assertSame('open', $created->status);
        $this->assertStringContainsString('Nadia', $created->title);

        // The author became a CRM lead so the conversation has an owner record.
        $contact = CRMContact::findOrFail($created->contact_id);
        $this->assertSame('Nadia Fouad', $contact->name);
        $this->assertSame('facebook', $contact->source);
        $this->assertSame('fb-author', $contact->custom_attributes['social_id']);

        $interaction->refresh();
        $this->assertSame('handled', $interaction->status);
        $this->assertSame($created->id, $interaction->crm_task_id);
        $this->assertSame($admin->id, $interaction->handled_by);
    }

    public function test_task_conversion_is_idempotent_and_assignee_must_belong_to_the_company(): void
    {
        $admin = $this->admin();
        $interaction = $this->interaction($admin->company_id, 'comment-one-task');
        $other = Company::create(['name' => 'Rival Co', 'email' => 'rival-assignee@example.com', 'status' => 'active']);
        $foreignUser = User::factory()->create(['company_id' => $other->id]);

        $this->postJson("/api/social/inbox/{$interaction->id}/task", ['assignee_id' => $foreignUser->id])
            ->assertUnprocessable();

        $first = $this->postJson("/api/social/inbox/{$interaction->id}/task")->assertCreated()->json('data.id');
        $second = $this->postJson("/api/social/inbox/{$interaction->id}/task")->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, CRMTask::where('company_id', $admin->company_id)->whereKey($first)->count());
    }

    /** An author we already know should not be duplicated as a new lead. */
    public function test_a_known_contact_is_reused_instead_of_duplicated(): void
    {
        $admin = $this->admin();
        $existing = CRMContact::create([
            'company_id' => $admin->company_id,
            'name' => 'Nadia Fouad',
            'type' => 'lead',
            'status' => 'new',
            'currency' => 'EGP',
            'custom_attributes' => ['social_id' => 'fb-author'],
        ]);

        $interaction = $this->interaction($admin->company_id, 'comment-known');
        $before = CRMContact::where('company_id', $admin->company_id)->count();

        $this->postJson("/api/social/inbox/{$interaction->id}/task")->assertCreated();

        $this->assertSame($before, CRMContact::where('company_id', $admin->company_id)->count());
        $this->assertSame($existing->id, $interaction->fresh()->crm_contact_id);
    }

    public function test_replying_posts_to_meta_and_closes_the_interaction(): void
    {
        $admin = $this->admin();
        $this->connection($admin);
        $this->page($admin);
        $interaction = $this->interaction($admin->company_id, 'comment-reply');

        Http::fake(['*comment-reply/comments*' => Http::response(['id' => 'reply-1'])]);

        $this->postJson("/api/social/inbox/{$interaction->id}/reply", ['message' => 'Yes, we do!'])
            ->assertOk()
            ->assertJsonPath('data.id', 'reply-1');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'comment-reply/comments')
            && $request['message'] === 'Yes, we do!');

        $this->assertSame('handled', $interaction->fresh()->status);
    }

    public function test_page_and_instagram_messages_land_in_the_inbox_and_can_be_replied_to(): void
    {
        $admin = $this->admin();
        $this->connection($admin);
        $this->page($admin, ['instagram_account_id' => 'ig-1']);

        (new ProcessMetaMessageWebhookEvent('ig-1', [
            'sender' => ['id' => 'ig-user-1', 'name' => 'Mona'],
            'message' => ['mid' => 'ig-message-1', 'text' => 'Is this available?'],
            'timestamp' => now()->getTimestampMs(),
        ]))->handle(app(SocialInboxService::class));

        $interaction = SocialInteraction::where('external_id', 'ig-message-1')->firstOrFail();
        $this->assertSame('instagram', $interaction->platform);
        $this->assertSame('message', $interaction->type);

        Http::fake(['*ig-1/messages*' => Http::response(['recipient_id' => 'ig-user-1', 'message_id' => 'reply-ig-1'])]);

        $this->postJson("/api/social/inbox/{$interaction->id}/reply", ['message' => 'Yes, it is.'])
            ->assertOk()
            ->assertJsonPath('data.message_id', 'reply-ig-1');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'ig-1/messages')
            && $request['recipient']['id'] === 'ig-user-1'
            && $request['message']['text'] === 'Yes, it is.');
    }

    public function test_whatsapp_messages_also_land_in_the_social_inbox_and_support_replies(): void
    {
        $admin = $this->admin();
        $this->connection($admin, [
            'whatsapp_enabled' => true,
            'whatsapp_phone_number_id' => 'phone-support',
        ]);

        app(MetaWhatsAppService::class)->handleInboundMessage(
            'phone-support',
            '201001234567',
            'WhatsApp Customer',
            'I need support',
            'wamid.support-1'
        );

        $interaction = SocialInteraction::where('external_id', 'wamid.support-1')->firstOrFail();
        $this->assertSame('whatsapp', $interaction->platform);
        $this->assertNotNull($interaction->crm_contact_id);

        Http::fake(['*phone-support/messages*' => Http::response(['messages' => [['id' => 'wamid.reply-1']]])]);
        $this->postJson("/api/social/inbox/{$interaction->id}/reply", ['message' => 'How can we help?'])
            ->assertOk()
            ->assertJsonPath('data.messages.0.id', 'wamid.reply-1');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'phone-support/messages')
            && $request['to'] === '201001234567'
            && $request['text']['body'] === 'How can we help?');
    }

    public function test_comments_can_be_backfilled_for_a_page(): void
    {
        $admin = $this->admin();
        $this->connection($admin);
        $page = $this->page($admin);

        Http::fake([
            '*page-1/posts*' => Http::response(['data' => [['id' => 'post-9', 'permalink_url' => 'https://fb/post-9']]]),
            '*post-9/comments*' => Http::response(['data' => [
                ['id' => 'c-1', 'message' => 'Price?', 'from' => ['id' => 'u1', 'name' => 'Ali'], 'created_time' => '2026-08-01T10:00:00+0000'],
                ['id' => 'c-2', 'message' => 'Interested', 'from' => ['id' => 'u2', 'name' => 'Sara'], 'created_time' => '2026-08-02T10:00:00+0000'],
            ]]),
        ]);

        $this->postJson("/api/social/inbox/pages/{$page->id}/import")
            ->assertOk()
            ->assertJsonPath('data.imported', 2);

        // A second run imports nothing new.
        $this->postJson("/api/social/inbox/pages/{$page->id}/import")
            ->assertOk()
            ->assertJsonPath('data.imported', 0);

        $this->assertSame(2, SocialInteraction::where('company_id', $admin->company_id)->count());
    }

    public function test_comment_backfill_explains_when_the_page_token_is_missing(): void
    {
        $admin = $this->admin();
        $this->connection($admin);
        $page = $this->page($admin, ['access_token' => null]);

        Http::fake();

        $this->postJson("/api/social/inbox/pages/{$page->id}/import")
            ->assertUnprocessable()
            ->assertJsonPath('errors.page.0', 'Re-sync this Page from Meta before importing comments.');

        Http::assertNothingSent();
    }

    public function test_publishing_to_a_page_posts_to_the_feed(): void
    {
        $admin = $this->admin();
        $this->connection($admin);
        $page = $this->page($admin);

        Http::fake(['*page-1/feed*' => Http::response(['id' => 'page-1_999'])]);

        $this->postJson('/api/social/publish', [
            'page_id' => $page->id,
            'platform' => 'facebook',
            'message' => 'New stock has arrived.',
        ])->assertCreated()->assertJsonPath('data.id', 'page-1_999');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'page-1/feed')
            && $request['message'] === 'New stock has arrived.');
    }

    /** Instagram needs a container first, then a publish call against it. */
    public function test_publishing_to_instagram_creates_a_container_then_publishes_it(): void
    {
        $admin = $this->admin();
        $this->connection($admin);
        $page = $this->page($admin, ['instagram_account_id' => 'ig-1']);

        config([
            'meta.instagram_container_poll_attempts' => 3,
            'meta.instagram_container_poll_delay_ms' => 0,
        ]);

        Http::fake([
            '*ig-1/media_publish' => Http::response(['id' => 'ig-media-1']),
            '*ig-1/media' => Http::response(['id' => 'container-1']),
            '*container-1*' => Http::sequence()
                ->push(['status_code' => 'IN_PROGRESS'])
                ->push(['status_code' => 'FINISHED']),
        ]);

        $this->postJson('/api/social/publish', [
            'page_id' => $page->id,
            'platform' => 'instagram',
            'image_url' => 'https://cdn.example.com/promo.jpg',
            'caption' => 'Now open',
        ])->assertCreated()->assertJsonPath('data.id', 'ig-media-1');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'ig-1/media_publish')
            && $request['creation_id'] === 'container-1');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'container-1')
            && $request->method() === 'GET'
            && $request['fields'] === 'status_code,status');
    }

    public function test_instagram_publish_stops_after_the_container_poll_limit(): void
    {
        $admin = $this->admin();
        $this->connection($admin);
        $page = $this->page($admin, ['instagram_account_id' => 'ig-1']);

        config([
            'meta.instagram_container_poll_attempts' => 2,
            'meta.instagram_container_poll_delay_ms' => 0,
        ]);

        Http::fake([
            '*ig-1/media_publish' => Http::response(['id' => 'must-not-publish']),
            '*ig-1/media' => Http::response(['id' => 'container-waiting']),
            '*container-waiting*' => Http::response(['status_code' => 'IN_PROGRESS']),
        ]);

        try {
            app(SocialPublishingService::class)->publishToInstagram(
                $page,
                'https://cdn.example.com/promo.jpg',
                'Now open',
            );
            $this->fail('Publishing should stop when the media container never becomes ready.');
        } catch (MetaGraphException $exception) {
            $this->assertSame(
                'Instagram is still processing the media. Try publishing again shortly.',
                $exception->getMessage(),
            );
        }

        Http::assertSentCount(3); // One create call and exactly two readiness checks.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'ig-1/media_publish'));
    }

    public function test_publishing_to_instagram_without_a_linked_account_is_refused(): void
    {
        $admin = $this->admin();
        $this->connection($admin);
        $page = $this->page($admin, ['instagram_account_id' => null]);

        $this->postJson('/api/social/publish', [
            'page_id' => $page->id,
            'platform' => 'instagram',
            'image_url' => 'https://cdn.example.com/promo.jpg',
        ])->assertStatus(422);
    }

    public function test_publishing_to_another_companys_page_is_refused(): void
    {
        $this->admin();
        $other = Company::create(['name' => 'Rival Co', 'email' => 'rival3@example.com', 'status' => 'active']);
        $connection = MetaConnection::create([
            'company_id' => $other->id,
            'status' => 'connected',
            'connection_method' => 'oauth',
            'app_id' => 'app-b',
            'app_secret' => 'secret-b',
            'access_token' => 'token-b',
        ]);
        $page = MetaPage::create([
            'company_id' => $other->id,
            'meta_connection_id' => $connection->id,
            'page_id' => 'page-b',
            'name' => 'Rival Page',
            'access_token' => 'page-token-b',
            'is_active' => true,
        ]);

        $this->postJson('/api/social/publish', [
            'page_id' => $page->id,
            'platform' => 'facebook',
            'message' => 'Hijack attempt',
        ])->assertForbidden();
    }

    private function postSigned(string $body, string $secret)
    {
        return $this->call('POST', '/api/meta/webhooks/leadgen', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ], $body);
    }

    private function interaction(int $companyId, string $externalId): SocialInteraction
    {
        return SocialInteraction::create([
            'company_id' => $companyId,
            'platform' => 'facebook',
            'type' => 'comment',
            'external_id' => $externalId,
            'parent_external_id' => 'post-1',
            'page_id' => 'page-1',
            'author_external_id' => 'fb-author',
            'author_name' => 'Nadia Fouad',
            'message' => 'Do you deliver to Alexandria?',
            'status' => 'new',
            'posted_at' => now(),
        ]);
    }

    private function page(User $admin, array $overrides = []): MetaPage
    {
        return MetaPage::create(array_merge([
            'company_id' => $admin->company_id,
            'meta_connection_id' => MetaConnection::where('company_id', $admin->company_id)->value('id'),
            'page_id' => 'page-1',
            'name' => 'Manzoma Store',
            'access_token' => 'page-token-a',
            'is_active' => true,
        ], $overrides));
    }

    private function connection(User $admin, array $overrides = []): MetaConnection
    {
        return MetaConnection::create(array_merge([
            'company_id' => $admin->company_id,
            'status' => 'connected',
            'connection_method' => 'oauth',
            'app_id' => 'app-a',
            'app_secret' => 'secret-a',
            'access_token' => 'token-a',
        ], $overrides));
    }

    private function admin(): User
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin);

        return $admin;
    }
}
