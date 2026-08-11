<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\Notifications\Notifications\EventNotification;
use App\Modules\TikTokIntegration\Exceptions\MissingAppCredentialsException;
use App\Modules\TikTokIntegration\Jobs\SendTikTokEvent;
use App\Modules\TikTokIntegration\Models\TikTokAdvertiser;
use App\Modules\TikTokIntegration\Models\TikTokConnection;
use App\Modules\TikTokIntegration\Models\TikTokEventLog;
use App\Modules\TikTokIntegration\Models\TikTokEventMapping;
use App\Modules\TikTokIntegration\Models\TikTokLeadFormMapping;
use App\Modules\TikTokIntegration\Services\TikTokAdvertiserService;
use App\Modules\TikTokIntegration\Services\TikTokEventService;
use App\Modules\TikTokIntegration\Services\TikTokLeadService;
use App\Modules\TikTokIntegration\Services\TikTokTokenService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TikTokIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** TikTok signals failure inside a 200 body, which is easy to miss. */
    public function test_an_error_code_in_a_200_response_is_treated_as_a_failure(): void
    {
        $admin = $this->admin();
        $connection = $this->connection($admin);

        Http::fake(['*advertiser/get*' => Http::response([
            'code' => 40001,
            'message' => 'Access token is invalid',
            'request_id' => 'req-1',
        ], 200)]);

        $this->assertFalse(app(TikTokTokenService::class)->verify($connection));
        $this->assertSame('expired', $connection->fresh()->status);
    }

    public function test_oauth_callback_stores_encrypted_tokens_and_syncs_advertisers(): void
    {
        $admin = $this->admin();
        $this->connection($admin, ['status' => 'pending', 'access_token' => null]);

        $url = $this->getJson('/api/tiktok/oauth/url')->assertOk()->json('data.url');
        $this->assertSame('ads.tiktok.com', parse_url($url, PHP_URL_HOST));
        $this->assertSame('/marketing_api/auth', parse_url($url, PHP_URL_PATH));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        Http::fake([
            '*oauth2/access_token*' => Http::response(['code' => 0, 'data' => [
                'access_token' => 'tt-access-token',
                'refresh_token' => 'tt-refresh-token',
                'access_token_expire_in' => 86400,
                'refresh_token_expire_in' => 31536000,
                'core_user_id' => 'core-1',
                'scope' => ['advertiser.list', 'campaign.list'],
            ]]),
            '*oauth2/advertiser/get*' => Http::response(['code' => 0, 'data' => [
                'list' => [['advertiser_id' => 'adv-1', 'advertiser_name' => 'Acme Ads']],
            ]]),
            '*advertiser/info*' => Http::response(['code' => 0, 'data' => [
                'list' => [['advertiser_id' => 'adv-1', 'advertiser_name' => 'Acme Ads', 'currency' => 'USD', 'timezone' => 'UTC', 'status' => 'STATUS_ENABLE']],
            ]]),
        ]);

        $this->postJson('/api/tiktok/oauth/callback', [
            'auth_code' => 'auth-code-1',
            'state' => $query['state'],
        ])->assertOk()->assertJsonPath('data.status', 'connected');

        $connection = TikTokConnection::where('company_id', $admin->company_id)->firstOrFail();
        $this->assertSame('tt-access-token', $connection->access_token);
        $this->assertSame('tt-refresh-token', $connection->refresh_token);
        $this->assertTrue($connection->canRefresh());

        // Tokens are encrypted at rest and never serialised.
        $raw = \DB::table('tiktok_connections')->where('id', $connection->id)->first();
        $this->assertNotSame('tt-access-token', $raw->access_token);
        $this->assertArrayNotHasKey('access_token', $connection->toArray());

        $this->assertSame('adv-1', TikTokAdvertiser::where('company_id', $admin->company_id)->value('advertiser_id'));
        $this->assertSame('adv-1', $connection->default_advertiser_id);
    }

    public function test_an_expiring_token_is_refreshed_with_the_rotated_refresh_token(): void
    {
        $admin = $this->admin();
        $connection = $this->connection($admin, [
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'access_token_expires_at' => now()->addDays(2),
            'refresh_token_expires_at' => now()->addYear(),
        ]);

        Http::fake(['*oauth2/refresh_token*' => Http::response(['code' => 0, 'data' => [
            'access_token' => 'new-access',
            'refresh_token' => 'new-refresh',
            'access_token_expire_in' => 86400,
        ]])]);

        $this->assertSame('refreshed', app(TikTokTokenService::class)->maintain($connection));

        $connection->refresh();
        $this->assertSame('new-access', $connection->access_token);
        // TikTok rotates the refresh token; keeping the old one breaks the next renewal.
        $this->assertSame('new-refresh', $connection->refresh_token);
        $this->assertSame('connected', $connection->status);
    }

    public function test_a_connection_that_cannot_refresh_is_expired_and_notified(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $connection = $this->connection($admin, [
            'access_token' => 'dead',
            'refresh_token' => null,
            'access_token_expires_at' => now()->subHour(),
        ]);

        $this->assertSame('expired', app(TikTokTokenService::class)->maintain($connection));
        $this->assertSame('expired', $connection->fresh()->status);
        Notification::assertSentTo($admin, EventNotification::class);
    }

    public function test_a_crm_lead_queues_a_conversion_event_with_hashed_identifiers(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $connection = $this->connection($admin, ['events_enabled' => true, 'pixel_code' => 'PIXEL1']);
        TikTokEventMapping::create([
            'company_id' => $admin->company_id,
            'tiktok_connection_id' => $connection->id,
            'trigger_source' => 'crm_lead_created',
            'event_name' => 'SubmitForm',
            'is_active' => true,
        ]);

        $contact = CRMContact::create([
            'company_id' => $admin->company_id,
            'name' => 'Lead Person',
            'email' => 'Lead@Example.com ',
            'phone' => '+20 100 000 0000',
            'type' => 'lead',
            'status' => 'new',
            'currency' => 'EGP',
        ]);

        $log = app(TikTokEventService::class)->recordEvent($admin->company_id, 'crm_lead_created', $contact);

        $this->assertNotNull($log);
        $this->assertSame('SubmitForm', $log->event_name);
        // Personal data leaves as SHA-256 of the normalised value, never raw.
        $this->assertSame(hash('sha256', 'lead@example.com'), $log->payload['user']['email']);
        $this->assertSame(hash('sha256', '201000000000'), $log->payload['user']['phone']);
        $this->assertStringNotContainsString('Lead@Example.com', json_encode($log->payload));
        Queue::assertPushed(SendTikTokEvent::class);
    }

    public function test_events_stay_unsent_until_the_company_enables_them(): void
    {
        $admin = $this->admin();
        $connection = $this->connection($admin, ['events_enabled' => false, 'pixel_code' => 'PIXEL1']);
        TikTokEventMapping::create([
            'company_id' => $admin->company_id,
            'tiktok_connection_id' => $connection->id,
            'trigger_source' => 'crm_lead_created',
            'event_name' => 'SubmitForm',
            'is_active' => true,
        ]);

        $contact = CRMContact::create([
            'company_id' => $admin->company_id,
            'name' => 'Quiet Lead',
            'type' => 'lead',
            'status' => 'new',
            'currency' => 'EGP',
        ]);

        $this->assertNull(app(TikTokEventService::class)->recordEvent($admin->company_id, 'crm_lead_created', $contact));
        $this->assertSame(0, TikTokEventLog::count());
    }

    public function test_a_failed_event_is_rescheduled_with_backoff_then_dead_lettered(): void
    {
        $admin = $this->admin();
        $connection = $this->connection($admin, ['events_enabled' => true, 'pixel_code' => 'PIXEL1']);
        $log = TikTokEventLog::create([
            'company_id' => $admin->company_id,
            'tiktok_connection_id' => $connection->id,
            'event_id' => 'evt-1',
            'event_name' => 'SubmitForm',
            'status' => 'pending',
            'payload' => ['event' => 'SubmitForm'],
            'attempts' => 0,
        ]);

        // A repeated Http::fake() would not override the first stub, so the
        // two outcomes are queued as a sequence. The client retries the
        // rate-limit response internally before giving the job the failure.
        Http::fakeSequence('*event/track*')
            ->push(['code' => 40100, 'message' => 'Too many requests'], 200)
            ->push(['code' => 40100, 'message' => 'Too many requests'], 200)
            ->push(['code' => 40100, 'message' => 'Too many requests'], 200)
            ->push(['code' => 40002, 'message' => 'Invalid pixel'], 200);

        (new SendTikTokEvent($log->id))->handle(app(TikTokEventService::class));

        $log->refresh();
        $this->assertSame('pending', $log->status);
        $this->assertSame(1, $log->attempts);
        $this->assertNotNull($log->next_retry_at);

        // Permanent error stops the retries.
        (new SendTikTokEvent($log->id))->handle(app(TikTokEventService::class));

        $log->refresh();
        $this->assertSame('failed', $log->status);
        $this->assertNull($log->next_retry_at);
    }

    public function test_advertisers_are_scoped_to_the_owning_company(): void
    {
        $admin = $this->admin();
        $this->connection($admin);

        $otherCompany = Company::factory()->create();
        $otherConnection = TikTokConnection::create([
            'company_id' => $otherCompany->id,
            'status' => 'connected',
            'access_token' => 'other-token',
        ]);
        TikTokAdvertiser::create([
            'company_id' => $otherCompany->id,
            'tiktok_connection_id' => $otherConnection->id,
            'advertiser_id' => 'foreign-adv',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/tiktok/advertisers')->assertOk();

        $this->assertStringNotContainsString('foreign-adv', $response->getContent());
    }

    public function test_a_user_without_tiktok_permission_is_refused(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = User::factory()->create(['company_id' => Company::firstOrFail()->id, 'is_active' => true]);
        $employee->assignRole('Employee');
        Sanctum::actingAs($employee);

        $this->getJson('/api/tiktok/connection')->assertForbidden();
    }

    public function test_disconnect_clears_credentials_but_keeps_history(): void
    {
        $admin = $this->admin();
        $connection = $this->connection($admin);
        TikTokAdvertiser::create([
            'company_id' => $admin->company_id,
            'tiktok_connection_id' => $connection->id,
            'advertiser_id' => 'adv-1',
            'is_active' => true,
        ]);

        $this->deleteJson('/api/tiktok/connection')->assertOk();

        $connection->refresh();
        $this->assertSame('disconnected', $connection->status);
        $this->assertNull($connection->access_token);
        $this->assertNull($connection->refresh_token);
        $this->assertDatabaseHas('tiktok_advertisers', ['advertiser_id' => 'adv-1', 'is_active' => 0]);
    }

    public function test_advertiser_sync_deactivates_accounts_that_disappeared(): void
    {
        $admin = $this->admin();
        $connection = $this->connection($admin);
        TikTokAdvertiser::create([
            'company_id' => $admin->company_id,
            'tiktok_connection_id' => $connection->id,
            'advertiser_id' => 'revoked-adv',
            'is_active' => true,
        ]);

        Http::fake([
            '*oauth2/advertiser/get*' => Http::response(['code' => 0, 'data' => [
                'list' => [['advertiser_id' => 'adv-1', 'advertiser_name' => 'Acme']],
            ]]),
            '*advertiser/info*' => Http::response(['code' => 0, 'data' => ['list' => []]]),
        ]);

        app(TikTokAdvertiserService::class)->sync($connection);

        $this->assertFalse(TikTokAdvertiser::where('advertiser_id', 'revoked-adv')->firstOrFail()->is_active);
        $this->assertTrue(TikTokAdvertiser::where('advertiser_id', 'adv-1')->firstOrFail()->is_active);
    }

    private function connection(User $admin, array $overrides = []): TikTokConnection
    {
        return TikTokConnection::create(array_merge([
            'company_id' => $admin->company_id,
            'status' => 'connected',
            'connection_method' => 'oauth',
            'app_id' => 'tt-app-1',
            'app_secret' => 'tt-secret-1',
            'access_token' => 'tt-token',
        ], $overrides));
    }

    private function admin(): User
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_the_setup_guide_is_per_company(): void
    {
        $admin = $this->admin();
        $this->connection($admin, ['webhook_verify_token' => 'tt-company-token']);

        $setup = $this->getJson('/api/tiktok/setup')->assertOk()->json('data');

        $this->assertSame('tt-app-1', $setup['app']['app_id']);
        $this->assertTrue($setup['app']['has_app_secret']);
        $this->assertSame('tt-company-token', $setup['webhook']['verify_token']);
        $this->assertFalse($setup['webhook']['supported']);
        $this->assertNull($setup['webhook']['callback_url']);
        // The secret is never returned, only the fact that one is stored.
        $this->assertStringNotContainsString('tt-secret-1', json_encode($setup));

        $steps = collect($setup['status'])->keyBy('step');
        $this->assertTrue($steps['credentials']['done']);
        $this->assertFalse($steps['advertisers']['done']);
    }

    public function test_connecting_without_company_credentials_is_refused(): void
    {
        $this->admin();

        $this->getJson('/api/tiktok/oauth/url')
            ->assertStatus(422)
            ->assertJsonPath('errors.app_id.0', 'Save your TikTok App ID and secret before connecting.');
    }

    public function test_a_connection_without_credentials_cannot_call_the_api(): void
    {
        $admin = $this->admin();
        $connection = $this->connection($admin, ['app_id' => null, 'app_secret' => null]);

        // No shared platform app exists to borrow credentials from.
        $this->expectException(MissingAppCredentialsException::class);

        app(TikTokAdvertiserService::class)->sync($connection);
    }

    public function test_lead_sync_requests_an_export_then_imports_it(): void
    {
        $admin = $this->admin();
        $mapping = $this->leadMapping($admin);

        // Phase one: ask TikTok to build the export.
        Http::fake(['*page/lead/task/create*' => Http::response(['code' => 0, 'data' => ['task_id' => 'task-1']])]);
        $this->assertSame('requested', app(TikTokLeadService::class)->sync($mapping));

        $mapping->refresh();
        $this->assertSame('task-1', $mapping->current_task_id);
        $this->assertSame('PROCESSING', $mapping->task_status);
        $this->assertNotNull($mapping->synced_through);

        // Phases two and three: still building, then ready. A second
        // Http::fake() would not override the first stub, so these are queued.
        Http::fakeSequence('*page/lead/task/get*')
            ->push(['code' => 0, 'data' => ['status' => 'PROCESSING']], 200)
            ->push(['code' => 0, 'data' => [
                'status' => 'SUCCESS',
                'list' => [[
                    'lead_id' => 'tt-lead-1',
                    'campaign_id' => 'tt-camp-1',
                    'adgroup_id' => 'tt-grp-1',
                    'ad_id' => 'tt-ad-1',
                    'field_data' => [
                        ['name' => 'email', 'values' => ['tiktoker@example.com']],
                        ['name' => 'name', 'values' => ['TikTok Lead']],
                    ],
                ]],
            ]], 200);

        $this->assertSame('pending', app(TikTokLeadService::class)->sync($mapping->fresh()));
        $this->assertSame('imported', app(TikTokLeadService::class)->sync($mapping->fresh()));

        $contact = CRMContact::where('tiktok_lead_id', 'tt-lead-1')->firstOrFail();
        $this->assertSame('tiktoker@example.com', $contact->email);
        $this->assertSame('tt-camp-1', $contact->tiktok_campaign_id);
        $this->assertSame('tt-ad-1', $contact->tiktok_ad_id);
        $this->assertSame($admin->company_id, $contact->company_id);

        $mapping->refresh();
        $this->assertNull($mapping->current_task_id);
        $this->assertNotNull($mapping->last_synced_at);
    }

    public function test_a_lead_is_never_imported_twice(): void
    {
        $admin = $this->admin();
        $mapping = $this->leadMapping($admin);
        $row = ['lead_id' => 'tt-dup', 'field_data' => [['name' => 'email', 'values' => ['dup@example.com']]]];

        $leads = app(TikTokLeadService::class);
        $leads->ingest($mapping, $row);
        $leads->ingest($mapping, $row);

        $this->assertSame(1, CRMContact::where('tiktok_lead_id', 'tt-dup')->count());
    }

    public function test_a_csv_export_is_parsed(): void
    {
        $admin = $this->admin();
        $mapping = $this->leadMapping($admin);
        $mapping->forceFill(['current_task_id' => 'task-csv', 'task_status' => 'PROCESSING', 'task_requested_at' => now()])->save();

        Http::fake([
            '*page/lead/task/get*' => Http::response(['code' => 0, 'data' => [
                'status' => 'SUCCESS',
                'download_url' => 'https://tiktok.example/export.csv',
            ]]),
            'tiktok.example/*' => Http::response("lead_id,email,name\ncsv-lead-1,csv@example.com,CSV Person\n"),
        ]);

        $this->assertSame('imported', app(TikTokLeadService::class)->sync($mapping));

        $contact = CRMContact::where('tiktok_lead_id', 'csv-lead-1')->firstOrFail();
        $this->assertSame('csv@example.com', $contact->email);
        $this->assertSame('CSV Person', $contact->name);
    }

    public function test_a_stalled_export_is_restarted_rather_than_blocking_forever(): void
    {
        $admin = $this->admin();
        $mapping = $this->leadMapping($admin);
        $mapping->forceFill([
            'current_task_id' => 'task-stuck',
            'task_status' => 'PROCESSING',
            'task_requested_at' => now()->subHours(3),
        ])->save();

        Http::fake(['*page/lead/task/create*' => Http::response(['code' => 0, 'data' => ['task_id' => 'task-fresh']])]);

        $this->assertSame('requested', app(TikTokLeadService::class)->sync($mapping));
        $this->assertSame('task-fresh', $mapping->fresh()->current_task_id);
    }

    public function test_lead_mappings_are_scoped_to_the_owning_company(): void
    {
        $admin = $this->admin();
        $this->leadMapping($admin);

        $otherCompany = Company::factory()->create();
        $otherConnection = TikTokConnection::create([
            'company_id' => $otherCompany->id,
            'status' => 'connected',
            'access_token' => 'other',
            'app_id' => 'a',
            'app_secret' => 'b',
        ]);
        $foreign = TikTokLeadFormMapping::create([
            'company_id' => $otherCompany->id,
            'tiktok_connection_id' => $otherConnection->id,
            'advertiser_id' => 'adv-x',
            'page_id' => 'foreign-page',
            'field_mapping' => ['email' => 'email'],
        ]);

        $this->getJson('/api/tiktok/lead-forms')
            ->assertOk()
            ->assertJsonMissing(['page_id' => 'foreign-page']);

        $this->postJson("/api/tiktok/lead-forms/{$foreign->id}/sync")->assertForbidden();
    }

    public function test_tiktok_lead_form_default_owner_must_belong_to_the_company(): void
    {
        $admin = $this->admin();
        $this->connection($admin);
        $otherCompany = Company::factory()->create();
        $foreignOwner = User::factory()->create(['company_id' => $otherCompany->id]);

        $payload = [
            'advertiser_id' => 'adv-owner-check',
            'page_id' => 'page-owner-check',
            'field_mapping' => ['email' => 'email'],
            'default_owner_id' => $foreignOwner->id,
        ];

        $this->postJson('/api/tiktok/lead-forms', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.default_owner_id.0', 'Choose a default owner that belongs to this company.');

        $this->assertDatabaseMissing('tiktok_lead_form_mappings', [
            'company_id' => $admin->company_id,
            'page_id' => 'page-owner-check',
        ]);

        $payload['default_owner_id'] = $admin->id;
        $mappingId = $this->postJson('/api/tiktok/lead-forms', $payload)
            ->assertCreated()
            ->assertJsonPath('data.default_owner_id', $admin->id)
            ->json('data.id');
        $mapping = TikTokLeadFormMapping::findOrFail($mappingId);

        $payload['default_owner_id'] = $foreignOwner->id;
        $this->putJson("/api/tiktok/lead-forms/{$mapping->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.default_owner_id.0', 'Choose a default owner that belongs to this company.');

        $this->assertSame($admin->id, $mapping->fresh()->default_owner_id);
    }

    private function leadMapping(User $admin): TikTokLeadFormMapping
    {
        $connection = TikTokConnection::where('company_id', $admin->company_id)->first()
            ?: $this->connection($admin);

        return TikTokLeadFormMapping::create([
            'company_id' => $admin->company_id,
            'tiktok_connection_id' => $connection->id,
            'advertiser_id' => 'adv-1',
            'page_id' => 'page-1',
            'field_mapping' => ['email' => 'email', 'name' => 'name'],
            'is_active' => true,
        ]);
    }
}
