<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\CRM\Models\CRMActivity;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\CRM\Models\CRMSegment;
use App\Modules\MetaIntegration\Jobs\ProcessMetaLeadWebhookEvent;
use App\Modules\MetaIntegration\Jobs\ProcessMetaWhatsAppWebhookEvent;
use App\Modules\MetaIntegration\Jobs\SendMetaConversionEvent;
use App\Modules\MetaIntegration\Jobs\SyncMetaAudienceJob;
use App\Modules\MetaIntegration\Models\MetaAudienceSync;
use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\MetaIntegration\Models\MetaEventLog;
use App\Modules\MetaIntegration\Models\MetaEventMapping;
use App\Modules\MetaIntegration\Models\MetaLeadFormMapping;
use App\Modules\MetaIntegration\Services\MetaAudienceService;
use App\Modules\MetaIntegration\Services\MetaLeadAdsService;
use App\Modules\MetaIntegration\Services\MetaWhatsAppService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MetaIntegrationModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_callback_creates_encrypted_connection(): void
    {
        $admin = $this->admin();
        // No platform app exists: the company saves its own credentials first.
        $this->postJson('/api/meta/connection/app-credentials', [
            'app_id' => 'app-id',
            'app_secret' => 'app-secret',
        ])->assertOk();

        $linkUrl = $this->getJson('/api/meta/oauth/url')->assertOk()->json('data.url');
        parse_str(parse_url($linkUrl, PHP_URL_QUERY), $query);

        Http::fake(function ($request) {
            return Http::response(['access_token' => 'long-lived-token', 'expires_in' => 5184000], 200);
        });

        $this->postJson('/api/meta/oauth/callback', [
            'code' => 'auth-code',
            'state' => $query['state'],
        ])->assertOk()->assertJsonPath('data.status', 'connected');

        $connection = MetaConnection::where('company_id', $admin->company_id)->firstOrFail();
        $this->assertSame('long-lived-token', $connection->access_token);
        $this->assertDatabaseHas('meta_connections', ['company_id' => $admin->company_id, 'status' => 'connected']);
    }

    public function test_company_can_connect_using_their_own_meta_app_credentials(): void
    {
        $admin = $this->admin();
        config(['meta.app_id' => null, 'meta.app_secret' => null]);

        $this->getJson('/api/meta/oauth/url')->assertStatus(422);

        $this->postJson('/api/meta/connection/app-credentials', [
            'app_id' => 'company-own-app-id',
            'app_secret' => 'company-own-app-secret',
        ])->assertOk()->assertJsonPath('data.app_id', 'company-own-app-id');

        $linkUrl = $this->getJson('/api/meta/oauth/url')->assertOk()->json('data.url');
        $this->assertStringContainsString('client_id=company-own-app-id', $linkUrl);
        parse_str(parse_url($linkUrl, PHP_URL_QUERY), $query);

        Http::fake(['*oauth/access_token*' => Http::response(['access_token' => 'own-app-token', 'expires_in' => 5184000], 200)]);

        $this->postJson('/api/meta/oauth/callback', [
            'code' => 'auth-code',
            'state' => $query['state'],
        ])->assertOk()->assertJsonPath('data.status', 'connected');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'client_id=company-own-app-id'));

        $connection = MetaConnection::where('company_id', $admin->company_id)->firstOrFail();
        $this->assertSame('own-app-token', $connection->access_token);
        $this->assertSame('company-own-app-secret', $connection->app_secret);
    }

    public function test_business_app_with_login_configuration_uses_config_id_instead_of_scopes(): void
    {
        $this->admin();

        $this->postJson('/api/meta/connection/app-credentials', [
            'app_id' => 'business-app-id',
            'app_secret' => 'business-app-secret',
            'config_id' => 'login-config-123',
        ])->assertOk();

        $linkUrl = $this->getJson('/api/meta/oauth/url')->assertOk()->json('data.url');
        parse_str(parse_url($linkUrl, PHP_URL_QUERY), $query);

        $this->assertSame('login-config-123', $query['config_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertArrayNotHasKey('scope', $query);
    }

    public function test_expired_oauth_state_is_rejected(): void
    {
        $this->admin();

        $this->postJson('/api/meta/oauth/callback', [
            'code' => 'auth-code',
            'state' => 'not-a-real-state',
        ])->assertStatus(422);
    }

    public function test_manual_credentials_are_stored_encrypted(): void
    {
        $admin = $this->admin();

        $this->postJson('/api/meta/connection/manual', [
            'access_token' => 'system-user-token',
            'business_id' => 'biz-1',
            'ad_account_id' => 'act_1',
            'pixel_id' => 'pixel-1',
        ])->assertCreated()->assertJsonPath('data.connection_method', 'manual');

        $raw = \DB::table('meta_connections')->where('company_id', $admin->company_id)->value('access_token');
        $this->assertNotSame('system-user-token', $raw);

        $connection = MetaConnection::where('company_id', $admin->company_id)->firstOrFail();
        $this->assertSame('system-user-token', $connection->access_token);
    }

    public function test_disconnect_revokes_at_meta_and_keeps_the_history(): void
    {
        $admin = $this->admin();
        $connection = $this->connectedAccount($admin);
        MetaEventMapping::create([
            'company_id' => $admin->company_id,
            'meta_connection_id' => $connection->id,
            'trigger_source' => 'crm_lead_created',
            'meta_event_name' => 'Lead',
        ]);
        MetaLeadFormMapping::create([
            'company_id' => $admin->company_id,
            'meta_connection_id' => $connection->id,
            'page_id' => 'page-1',
            'form_id' => 'form-1',
            'field_mapping' => ['email' => 'email'],
        ]);

        Http::fake(['*' => Http::response(['success' => true])]);

        $this->deleteJson('/api/meta/connection')->assertOk();

        // The token is revoked at Meta, not just forgotten here.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'me/permissions') && $request->method() === 'DELETE');

        // The connection is retained so event logs and mappings keep their
        // foreign keys; it simply can no longer call Meta.
        $connection->refresh();
        $this->assertSame('disconnected', $connection->status);
        $this->assertNull($connection->access_token);
        $this->assertNotNull($connection->disconnected_at);
        $this->assertDatabaseHas('meta_event_mappings', ['company_id' => $admin->company_id]);
        $this->assertDatabaseHas('meta_lead_form_mappings', ['company_id' => $admin->company_id]);
    }

    public function test_disconnect_with_purge_removes_everything(): void
    {
        $admin = $this->admin();
        $connection = $this->connectedAccount($admin);
        MetaEventMapping::create([
            'company_id' => $admin->company_id,
            'meta_connection_id' => $connection->id,
            'trigger_source' => 'crm_lead_created',
            'meta_event_name' => 'Lead',
        ]);

        Http::fake(['*' => Http::response(['success' => true])]);

        $this->deleteJson('/api/meta/connection?purge=1')->assertOk();

        $this->assertDatabaseMissing('meta_connections', ['company_id' => $admin->company_id]);
        $this->assertDatabaseMissing('meta_event_mappings', ['company_id' => $admin->company_id]);
    }

    public function test_user_without_meta_permission_is_forbidden(): void
    {
        $this->admin();
        $employee = User::factory()->create([
            'company_id' => User::where('email', 'company.admin@example.com')->value('company_id'),
        ]);
        $employee->assignRole('Employee');
        Sanctum::actingAs($employee);

        $this->getJson('/api/meta/connection')->assertForbidden();
    }

    public function test_lead_created_dispatches_conversion_event(): void
    {
        $admin = $this->admin();
        $connection = $this->connectedAccount($admin);
        MetaEventMapping::create([
            'company_id' => $admin->company_id,
            'meta_connection_id' => $connection->id,
            'trigger_source' => 'crm_lead_created',
            'meta_event_name' => 'Lead',
            'is_active' => true,
        ]);

        Queue::fake();

        $this->postJson('/api/crm/contacts', [
            'owner_id' => $admin->id,
            'type' => 'lead',
            'name' => 'Sara Ali',
            'email' => 'sara@example.com',
            'phone' => '+201000000001',
        ])->assertCreated();

        $log = MetaEventLog::where('company_id', $admin->company_id)->where('trigger_source', 'crm_lead_created')->first();
        $this->assertNotNull($log);
        $this->assertSame('Lead', $log->event_name);
        Queue::assertPushed(SendMetaConversionEvent::class);
    }

    public function test_send_conversion_event_job_marks_log_sent(): void
    {
        $admin = $this->admin();
        $connection = $this->connectedAccount($admin);

        $log = MetaEventLog::create([
            'company_id' => $admin->company_id,
            'meta_connection_id' => $connection->id,
            'event_id' => (string) Str::uuid(),
            'event_name' => 'Lead',
            'trigger_source' => 'crm_lead_created',
            'payload' => ['event_name' => 'Lead', 'event_id' => 'evt-1'],
            'status' => 'pending',
        ]);

        Http::fake(['*/events*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'trace-1'], 200)]);

        (new SendMetaConversionEvent($log->id))->handle();

        $this->assertSame('sent', $log->fresh()->status);
    }

    public function test_send_conversion_event_job_dead_letters_on_invalid_token(): void
    {
        $admin = $this->admin();
        $connection = $this->connectedAccount($admin);

        $log = MetaEventLog::create([
            'company_id' => $admin->company_id,
            'meta_connection_id' => $connection->id,
            'event_id' => (string) Str::uuid(),
            'event_name' => 'Lead',
            'trigger_source' => 'crm_lead_created',
            'payload' => ['event_name' => 'Lead', 'event_id' => 'evt-2'],
            'status' => 'pending',
        ]);

        Http::fake(['*/events*' => Http::response(['error' => ['message' => 'Invalid token', 'code' => 190]], 401)]);

        (new SendMetaConversionEvent($log->id))->handle();

        $this->assertSame('dead_letter', $log->fresh()->status);
        $this->assertSame('error', $connection->fresh()->status);
    }

    public function test_webhook_hub_challenge_verification(): void
    {
        // The verify token belongs to one company's connection.
        $admin = $this->admin();
        $this->connectedAccount($admin, ['webhook_verify_token' => 'verify-me']);

        $this->get('/api/meta/webhooks/leadgen?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=123456')
            ->assertOk()
            ->assertSee('123456');

        $this->get('/api/meta/webhooks/leadgen?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=123456')
            ->assertStatus(403);
    }

    public function test_webhook_signature_verification_and_lead_ingestion(): void
    {
        $admin = $this->admin();
        $connection = $this->connectedAccount($admin);
        MetaLeadFormMapping::create([
            'company_id' => $admin->company_id,
            'meta_connection_id' => $connection->id,
            'page_id' => 'page-1',
            'form_id' => 'form-1',
            'field_mapping' => ['email' => 'email', 'full_name' => 'name', 'phone_number' => 'phone'],
            'default_owner_id' => $admin->id,
        ]);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'field' => 'leadgen',
                    'value' => ['leadgen_id' => 'lead-123', 'page_id' => 'page-1', 'form_id' => 'form-1'],
                ]],
            ]],
        ];
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'app-secret');

        Http::fake(['*lead-123*' => Http::response([
            'field_data' => [
                ['name' => 'email', 'values' => ['lead@example.com']],
                ['name' => 'full_name', 'values' => ['Lead Person']],
                ['name' => 'phone_number', 'values' => ['+201000000002']],
            ],
        ], 200)]);

        Queue::fake();

        $this->call('POST', '/api/meta/webhooks/leadgen', [], [], [], [
            'HTTP_X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        Queue::assertPushed(ProcessMetaLeadWebhookEvent::class);

        (new MetaLeadAdsService)->ingest('page-1', 'form-1', 'lead-123');

        $this->assertDatabaseHas('crm_contacts', [
            'company_id' => $admin->company_id,
            'meta_lead_id' => 'lead-123',
            'email' => 'lead@example.com',
        ]);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        config(['meta.app_secret' => 'app-secret']);

        $this->call('POST', '/api/meta/webhooks/leadgen', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256=invalid',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['entry' => []]))->assertStatus(403);
    }

    public function test_whatsapp_settings_saved_and_template_message_sent(): void
    {
        $admin = $this->admin();
        $connection = $this->connectedAccount($admin, ['business_id' => 'biz-1']);

        $this->putJson('/api/meta/whatsapp/settings', [
            'whatsapp_enabled' => true,
            'whatsapp_business_account_id' => 'waba-1',
            'whatsapp_phone_number_id' => 'phone-1',
            'whatsapp_phone_number' => '+201234567890',
        ])->assertOk()->assertJsonPath('data.whatsapp_enabled', true);

        $contact = CRMContact::create([
            'company_id' => $admin->company_id,
            'type' => 'lead',
            'name' => 'WA Lead',
            'phone' => '+201000000005',
        ]);

        Http::fake(['*phone-1/messages*' => Http::response([
            'messaging_product' => 'whatsapp',
            'messages' => [['id' => 'wamid.123']],
        ], 200)]);

        $this->postJson('/api/meta/whatsapp/send', [
            'contact_id' => $contact->id,
            'template_name' => 'hello_world',
            'language' => 'en_US',
        ])->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'phone-1/messages')
                && $request['to'] === '201000000005'
                && $request['template']['name'] === 'hello_world';
        });

        $this->assertDatabaseHas('crm_activities', [
            'company_id' => $admin->company_id,
            'contact_id' => $contact->id,
            'type' => 'whatsapp',
        ]);
    }

    public function test_inbound_whatsapp_message_creates_lead_and_activity(): void
    {
        $admin = $this->admin();
        $this->connectedAccount($admin, [
            'app_secret' => 'tenant-secret',
            'whatsapp_enabled' => true,
            'whatsapp_phone_number_id' => 'phone-9',
        ]);

        config(['meta.app_secret' => null]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'phone-9'],
                        'contacts' => [['profile' => ['name' => 'Wa Sender']]],
                        'messages' => [[
                            'from' => '201000000006',
                            'id' => 'wamid.inbound-1',
                            'text' => ['body' => 'Hello, I need a quote'],
                        ]],
                    ],
                ]],
            ]],
        ];
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'tenant-secret');

        Queue::fake();

        $this->call('POST', '/api/meta/webhooks/leadgen', [], [], [], [
            'HTTP_X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        Queue::assertPushed(ProcessMetaWhatsAppWebhookEvent::class);

        app(MetaWhatsAppService::class)
            ->handleInboundMessage('phone-9', '201000000006', 'Wa Sender', 'Hello, I need a quote', 'wamid.inbound-1');

        $this->assertDatabaseHas('crm_contacts', [
            'company_id' => $admin->company_id,
            'phone' => '201000000006',
            'source' => 'whatsapp',
            'type' => 'lead',
        ]);
        $this->assertDatabaseHas('crm_activities', [
            'company_id' => $admin->company_id,
            'type' => 'whatsapp',
            'body' => 'Hello, I need a quote',
        ]);

        // Redelivery of the same message id is a no-op.
        $result = app(MetaWhatsAppService::class)
            ->handleInboundMessage('phone-9', '201000000006', 'Wa Sender', 'Hello, I need a quote', 'wamid.inbound-1');
        $this->assertNull($result);
        $this->assertSame(1, CRMActivity::where('type', 'whatsapp')->count());
    }

    public function test_audience_sync_batches_hashed_identifiers(): void
    {
        $admin = $this->admin();
        $connection = $this->connectedAccount($admin, ['business_id' => 'biz-1']);
        $segment = CRMSegment::where('company_id', $admin->company_id)->first();
        for ($i = 0; $i < 3; $i++) {
            CRMContact::create([
                'company_id' => $admin->company_id,
                'type' => 'prospect',
                'name' => "Prospect {$i}",
                'email' => "prospect{$i}@example.com",
            ]);
        }

        Http::fake([
            '*customaudiences' => Http::response(['id' => 'aud-1'], 200),
            '*aud-1/users' => Http::response(['num_received' => 3], 200),
            '*aud-1?*' => Http::response(['approximate_count_upper_bound' => 3], 200),
        ]);

        $sync = MetaAudienceSync::create([
            'company_id' => $admin->company_id,
            'meta_connection_id' => $connection->id,
            'crm_segment_id' => $segment->id,
            'audience_name' => 'Prospects',
        ]);

        app(MetaAudienceService::class)->createAudience($sync);
        (new SyncMetaAudienceJob($sync->id))->handle(app(MetaAudienceService::class));

        $this->assertSame('synced', $sync->fresh()->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'aud-1/users'));
    }

    public function test_contact_deletion_removes_from_synced_audiences(): void
    {
        $admin = $this->admin();
        $connection = $this->connectedAccount($admin, ['business_id' => 'biz-1']);
        $segment = CRMSegment::where('company_id', $admin->company_id)->first();
        $contact = CRMContact::create(['company_id' => $admin->company_id, 'type' => 'prospect', 'name' => 'Delete Me', 'email' => 'delete-me@example.com']);

        MetaAudienceSync::create([
            'company_id' => $admin->company_id,
            'meta_connection_id' => $connection->id,
            'crm_segment_id' => $segment->id,
            'audience_name' => 'Prospects',
            'meta_audience_id' => 'aud-1',
        ]);

        Http::fake(['*aud-1/users*' => Http::response(['num_invalidated' => 1], 200)]);

        $this->deleteJson("/api/crm/contacts/{$contact->id}")->assertOk();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), 'aud-1/users'));
    }

    private function connectedAccount(User $admin, array $overrides = []): MetaConnection
    {
        return MetaConnection::create(array_merge([
            'company_id' => $admin->company_id,
            'connection_method' => 'manual',
            'status' => 'connected',
            'access_token' => 'test-token',
            'pixel_id' => 'pixel-1',
            // Per-tenant app credentials: webhooks are signed with these.
            'app_id' => 'app-id',
            'app_secret' => 'app-secret',
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
