<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\MetaIntegration\Models\MetaLeadFormMapping;
use App\Modules\MetaIntegration\Models\MetaPage;
use App\Modules\MetaIntegration\Services\MetaLeadAdsService;
use App\Modules\MetaIntegration\Services\MetaPageService;
use App\Modules\MetaIntegration\Services\MetaTokenService;
use App\Modules\Notifications\Notifications\EventNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The token lifecycle, page/Instagram assets and webhook subscriptions added in
 * the Meta rebuild.
 */
class MetaConnectionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_token_close_to_expiry_is_re_exchanged_before_it_dies(): void
    {
        $admin = $this->admin();
        $connection = $this->connection($admin, [
            'access_token' => 'about-to-expire',
            'access_token_expires_at' => now()->addDays(3),
        ]);

        Http::fake([
            '*debug_token*' => Http::response(['data' => [
                'is_valid' => true,
                'type' => 'USER',
                'expires_at' => now()->addDays(3)->timestamp,
                'scopes' => ['ads_management'],
            ]]),
            '*oauth/access_token*' => Http::response(['access_token' => 'renewed-token', 'expires_in' => 5184000]),
            '*me/permissions*' => Http::response(['data' => array_map(
                fn (string $scope) => ['permission' => $scope, 'status' => 'granted'],
                config('meta.required_scopes'),
            )]),
        ]);

        $outcome = app(MetaTokenService::class)->maintain($connection);

        $connection->refresh();
        $this->assertSame('refreshed', $outcome);
        $this->assertSame('renewed-token', $connection->access_token);
        $this->assertTrue($connection->access_token_expires_at->isAfter(now()->addDays(50)));
        $this->assertSame('connected', $connection->status);
        $this->assertNotNull($connection->token_refreshed_at);
    }

    public function test_a_system_user_token_is_recognised_and_left_alone(): void
    {
        $admin = $this->admin();
        $connection = $this->connection($admin, ['access_token' => 'system-user-token']);

        Http::fake([
            '*debug_token*' => Http::response(['data' => [
                'is_valid' => true,
                'type' => 'SYSTEM_USER',
                'expires_at' => 0,
                'scopes' => ['ads_management'],
            ]]),
            '*me/permissions*' => Http::response(['data' => array_map(
                fn (string $scope) => ['permission' => $scope, 'status' => 'granted'],
                config('meta.required_scopes'),
            )]),
        ]);

        $outcome = app(MetaTokenService::class)->maintain($connection);

        $connection->refresh();
        $this->assertSame('permanent', $outcome);
        $this->assertSame('system_user', $connection->token_type);
        $this->assertNull($connection->access_token_expires_at);
        // No re-exchange attempted: these tokens never expire.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'fb_exchange_token'));
    }

    public function test_an_invalid_token_marks_the_connection_expired_and_notifies(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $connection = $this->connection($admin, ['access_token' => 'dead-token']);

        Http::fake(['*debug_token*' => Http::response(['data' => ['is_valid' => false]])]);

        $outcome = app(MetaTokenService::class)->maintain($connection);

        $connection->refresh();
        $this->assertSame('expired', $outcome);
        $this->assertSame('expired', $connection->status);
        Notification::assertSentTo($admin, EventNotification::class);
    }

    public function test_a_declined_permission_degrades_the_connection(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $connection = $this->connection($admin);

        Http::fake(['*me/permissions*' => Http::response(['data' => [
            ['permission' => 'ads_management', 'status' => 'granted'],
            ['permission' => 'leads_retrieval', 'status' => 'declined'],
        ]])]);

        $result = app(MetaTokenService::class)->syncPermissions($connection);

        $connection->refresh();
        $this->assertContains('leads_retrieval', $result['missing']);
        $this->assertSame('degraded', $connection->status);
        $this->assertSame(['ads_management'], $connection->granted_scopes);
        $this->assertSame(['leads_retrieval'], $connection->declined_scopes);
    }

    public function test_pages_and_instagram_accounts_are_stored_without_exposing_tokens(): void
    {
        $admin = $this->admin();
        $connection = $this->connection($admin);

        Http::fake(['*me/accounts*' => Http::response(['data' => [
            [
                'id' => 'page-1',
                'name' => 'Acme Store',
                'category' => 'Retail',
                'access_token' => 'PAGE-TOKEN',
                'tasks' => ['MANAGE'],
                'instagram_business_account' => ['id' => 'ig-1', 'username' => 'acme'],
            ],
            ['id' => 'page-2', 'name' => 'Acme Support', 'access_token' => 'PAGE-TOKEN-2'],
        ]])]);

        app(MetaPageService::class)->sync($connection);

        $page = MetaPage::where('page_id', 'page-1')->firstOrFail();
        $this->assertSame('ig-1', $page->instagram_account_id);
        $this->assertSame('acme', $page->instagram_username);
        // Stored for server-side use, encrypted, and never serialised.
        $this->assertSame('PAGE-TOKEN', $page->access_token);
        $this->assertArrayNotHasKey('access_token', $page->toArray());

        $response = $this->getJson('/api/meta/pages')->assertOk();
        $this->assertStringNotContainsString('PAGE-TOKEN', $response->getContent());

        $this->getJson('/api/meta/instagram/accounts')
            ->assertOk()
            ->assertJsonPath('data.0.instagram_account_id', 'ig-1');
    }

    public function test_a_page_that_is_no_longer_administered_is_deactivated_not_deleted(): void
    {
        $admin = $this->admin();
        $connection = $this->connection($admin);
        MetaPage::create([
            'company_id' => $admin->company_id,
            'meta_connection_id' => $connection->id,
            'page_id' => 'gone-page',
            'name' => 'Old Page',
            'is_active' => true,
        ]);

        Http::fake(['*me/accounts*' => Http::response(['data' => [
            ['id' => 'page-1', 'name' => 'Still Here', 'access_token' => 'token'],
        ]])]);

        app(MetaPageService::class)->sync($connection);

        $this->assertFalse(MetaPage::where('page_id', 'gone-page')->firstOrFail()->is_active);
    }

    public function test_a_page_can_be_subscribed_to_webhooks(): void
    {
        $admin = $this->admin();
        $connection = $this->connection($admin);
        $page = MetaPage::create([
            'company_id' => $admin->company_id,
            'meta_connection_id' => $connection->id,
            'page_id' => 'page-1',
            'name' => 'Acme',
            'access_token' => 'page-token',
        ]);

        Http::fake(['*subscribed_apps*' => Http::response(['success' => true])]);

        $this->postJson("/api/meta/pages/{$page->id}/subscribe")->assertOk();

        $page->refresh();
        $this->assertNotNull($page->webhook_subscribed_at);
        $this->assertSame(config('meta.webhook_page_fields'), $page->webhook_fields);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'page-1/subscribed_apps')
            && str_contains((string) $request['subscribed_fields'], 'leadgen'));
    }

    public function test_a_page_belonging_to_another_company_cannot_be_subscribed(): void
    {
        $admin = $this->admin();
        $other = MetaConnection::create([
            'company_id' => Company::factory()->create()->id,
            'connection_method' => 'manual',
            'status' => 'connected',
            'access_token' => 'other-token',
        ]);
        $foreignPage = MetaPage::create([
            'company_id' => $other->company_id,
            'meta_connection_id' => $other->id,
            'page_id' => 'foreign-page',
            'access_token' => 'foreign-token',
        ]);

        $this->postJson("/api/meta/pages/{$foreignPage->id}/subscribe")->assertForbidden();
    }

    public function test_lead_ingestion_records_campaign_attribution(): void
    {
        $admin = $this->admin();
        $connection = $this->connection($admin);
        MetaLeadFormMapping::create([
            'company_id' => $admin->company_id,
            'meta_connection_id' => $connection->id,
            'page_id' => 'page-1',
            'form_id' => 'form-1',
            'field_mapping' => ['email' => 'email', 'full_name' => 'name'],
            'is_active' => true,
        ]);

        Http::fake(['*' => Http::response([
            'field_data' => [
                ['name' => 'email', 'values' => ['buyer@example.com']],
                ['name' => 'full_name', 'values' => ['Buyer One']],
            ],
            'campaign_id' => 'camp-1',
            'adset_id' => 'adset-1',
            'ad_id' => 'ad-1',
            'platform' => 'instagram',
            'form_id' => 'form-1',
        ])]);

        app(MetaLeadAdsService::class)->ingest('page-1', 'form-1', 'lead-attr-1');

        $contact = CRMContact::where('meta_lead_id', 'lead-attr-1')->firstOrFail();
        $this->assertSame('camp-1', $contact->meta_campaign_id);
        $this->assertSame('adset-1', $contact->meta_adset_id);
        $this->assertSame('ad-1', $contact->meta_ad_id);
        $this->assertSame('instagram', $contact->meta_platform);
    }

    public function test_the_maintenance_command_reports_what_it_did(): void
    {
        $admin = $this->admin();
        $this->connection($admin, ['access_token' => 'valid-token', 'access_token_expires_at' => now()->addDays(45)]);

        Http::fake([
            '*debug_token*' => Http::response(['data' => [
                'is_valid' => true,
                'type' => 'USER',
                'expires_at' => now()->addDays(45)->timestamp,
            ]]),
            '*me/permissions*' => Http::response(['data' => array_map(
                fn (string $scope) => ['permission' => $scope, 'status' => 'granted'],
                config('meta.required_scopes'),
            )]),
        ]);

        $this->artisan('meta:maintain-connections')
            ->expectsOutputToContain('healthy')
            ->assertExitCode(0);
    }

    private function connection(User $admin, array $overrides = []): MetaConnection
    {
        return MetaConnection::create(array_merge([
            'company_id' => $admin->company_id,
            'connection_method' => 'oauth',
            'status' => 'connected',
            'access_token' => 'test-token',
            'app_id' => 'app-1',
            'app_secret' => 'secret-1',
            'pixel_id' => 'pixel-1',
        ], $overrides));
    }

    private function admin(): User
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_the_setup_guide_hands_the_company_what_their_own_app_needs(): void
    {
        $admin = $this->admin();
        $connection = $this->connection($admin, ['webhook_verify_token' => 'company-token']);

        $setup = $this->getJson('/api/meta/setup')->assertOk()->json('data');

        // Each company configures their own Meta App, so they must be able to
        // read the verify token back — it is hidden on the connection payload.
        $this->assertSame('company-token', $setup['webhook']['verify_token']);
        $this->assertStringContainsString('/api/meta/webhooks/leadgen', $setup['webhook']['callback_url']);
        $this->assertNotEmpty($setup['oauth']['redirect_uri']);
        $this->assertSame(config('meta.required_scopes'), $setup['oauth']['required_scopes']);
        $this->assertSame('app-1', $setup['app']['app_id']);
        $this->assertTrue($setup['app']['has_app_secret']);

        // The secret itself is never returned.
        $this->assertStringNotContainsString('secret-1', json_encode($setup));

        $steps = collect($setup['status'])->keyBy('step');
        $this->assertTrue($steps['credentials']['done']);
        $this->assertFalse($steps['pages']['done']);
    }

    public function test_rotating_the_verify_token_issues_a_new_one(): void
    {
        $admin = $this->admin();
        $connection = $this->connection($admin, ['webhook_verify_token' => 'old-token']);

        $issued = $this->postJson('/api/meta/setup/verify-token')->assertOk()->json('data.verify_token');

        $this->assertNotSame('old-token', $issued);
        $this->assertSame($issued, $connection->fresh()->webhook_verify_token);
        // The hash used for webhook matching follows the rotation.
        $this->assertSame(hash('sha256', $issued), $connection->fresh()->webhook_verify_token_hash);
    }

    public function test_the_setup_guide_is_scoped_to_the_caller_company(): void
    {
        $admin = $this->admin();
        $this->connection($admin, ['webhook_verify_token' => 'ours']);

        $otherCompany = Company::factory()->create();
        MetaConnection::create([
            'company_id' => $otherCompany->id,
            'connection_method' => 'oauth',
            'status' => 'connected',
            'webhook_verify_token' => 'theirs',
        ]);

        $setup = $this->getJson('/api/meta/setup')->assertOk()->json('data');

        $this->assertSame('ours', $setup['webhook']['verify_token']);
    }
}
