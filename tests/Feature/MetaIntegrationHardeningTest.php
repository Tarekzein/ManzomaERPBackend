<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\CRM\Models\CRMSegment;
use App\Modules\MetaIntegration\Models\MetaAudienceSync;
use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\MetaIntegration\Models\MetaLeadFormMapping;
use App\Modules\MetaIntegration\Services\MetaLeadAdsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression cover for the issues found in the Meta integration audit.
 */
class MetaIntegrationHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_access_tokens_are_never_returned_to_the_client(): void
    {
        $admin = $this->admin();
        $this->connection($admin);

        Http::fake([
            '*me/accounts*' => Http::response(['data' => [
                ['id' => 'page-1', 'name' => 'Acme Page', 'access_token' => 'PAGE-TOKEN-SECRET'],
            ]]),
        ]);

        $response = $this->getJson('/api/meta/assets/pages')->assertOk();

        // A page token can post as the Page and read its leads: it stays server-side.
        $response->assertJsonPath('data.0.id', 'page-1');
        $this->assertStringNotContainsString('PAGE-TOKEN-SECRET', $response->getContent());
        $this->assertArrayNotHasKey('access_token', $response->json('data.0'));
    }

    public function test_saved_meta_assets_can_be_cleared_without_erasing_omitted_selections(): void
    {
        $admin = $this->admin();
        $connection = $this->connection($admin, [
            'business_id' => 'business-1',
            'ad_account_id' => 'act-1',
            'pixel_id' => 'pixel-1',
            'page_ids' => ['page-1'],
            'default_page_id' => 'page-1',
        ]);

        $this->postJson('/api/meta/connection/assets', [
            'business_id' => null,
            'ad_account_id' => null,
            'pixel_id' => null,
        ])->assertOk()
            ->assertJsonPath('data.business_id', null)
            ->assertJsonPath('data.ad_account_id', null)
            ->assertJsonPath('data.pixel_id', null)
            ->assertJsonPath('data.page_ids.0', 'page-1')
            ->assertJsonPath('data.default_page_id', 'page-1');

        $this->postJson('/api/meta/connection/assets', [
            'page_ids' => [],
            'default_page_id' => null,
        ])->assertOk()
            ->assertJsonPath('data.page_ids', [])
            ->assertJsonPath('data.default_page_id', null);

        $connection->refresh();
        $this->assertNull($connection->business_id);
        $this->assertNull($connection->ad_account_id);
        $this->assertNull($connection->pixel_id);
        $this->assertSame([], $connection->page_ids);
        $this->assertNull($connection->default_page_id);
    }

    public function test_requested_scopes_cover_the_implemented_social_inbox_actions(): void
    {
        $socialScopes = [
            'pages_manage_engagement', // Reply to Facebook comments.
            'pages_messaging', // Receive and reply to Facebook messages.
            'pages_read_engagement', // Read Page-owned posts before loading comments.
            'pages_read_user_content', // Read comments written by Page visitors.
            'instagram_basic', // Resolve the linked professional account.
            'instagram_manage_comments', // Receive and reply to Instagram comments.
            'instagram_manage_messages', // Receive and reply to Instagram messages.
        ];

        foreach ($socialScopes as $scope) {
            $this->assertContains($scope, config('meta.scopes'), "OAuth does not request {$scope}.");
            $this->assertContains($scope, config('meta.required_scopes'), "Health checks do not require {$scope}.");
        }
    }

    public function test_meta_lead_form_default_owner_must_belong_to_the_company(): void
    {
        $admin = $this->admin();
        $this->connection($admin);
        $otherCompany = Company::factory()->create();
        $foreignOwner = User::factory()->create(['company_id' => $otherCompany->id]);

        $payload = [
            'page_id' => 'page-owner-check',
            'form_id' => 'form-owner-check',
            'field_mapping' => ['email' => 'email'],
            'default_owner_id' => $foreignOwner->id,
        ];

        $this->postJson('/api/meta/lead-forms', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.default_owner_id.0', 'Choose a default owner that belongs to this company.');

        $this->assertDatabaseMissing('meta_lead_form_mappings', [
            'company_id' => $admin->company_id,
            'form_id' => 'form-owner-check',
        ]);

        $payload['default_owner_id'] = $admin->id;
        $mappingId = $this->postJson('/api/meta/lead-forms', $payload)
            ->assertCreated()
            ->assertJsonPath('data.default_owner_id', $admin->id)
            ->json('data.id');
        $mapping = MetaLeadFormMapping::findOrFail($mappingId);

        $payload['default_owner_id'] = $foreignOwner->id;
        $this->putJson("/api/meta/lead-forms/{$mapping->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.default_owner_id.0', 'Choose a default owner that belongs to this company.');

        $this->assertSame($admin->id, $mapping->fresh()->default_owner_id);
    }

    public function test_meta_audience_update_rejects_a_foreign_crm_segment(): void
    {
        $admin = $this->admin();
        $connection = $this->connection($admin);
        $ownSegment = CRMSegment::create([
            'company_id' => $admin->company_id,
            'name' => 'Our buyers',
            'criteria' => [],
        ]);
        $otherCompany = Company::factory()->create();
        $foreignSegment = CRMSegment::create([
            'company_id' => $otherCompany->id,
            'name' => 'Their buyers',
            'criteria' => [],
        ]);
        $sync = MetaAudienceSync::create([
            'company_id' => $admin->company_id,
            'meta_connection_id' => $connection->id,
            'crm_segment_id' => $ownSegment->id,
            'audience_name' => 'Our audience',
        ]);

        $this->putJson("/api/meta/audiences/{$sync->id}", [
            'crm_segment_id' => $foreignSegment->id,
            'audience_name' => 'Cross-tenant audience',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.crm_segment_id.0', 'Choose a CRM segment that belongs to this company.');

        $sync->refresh();
        $this->assertSame($ownSegment->id, $sync->crm_segment_id);
        $this->assertSame('Our audience', $sync->audience_name);
    }

    public function test_super_admin_must_name_the_company_it_operates_on(): void
    {
        $this->seed(DatabaseSeeder::class);
        $superAdmin = User::where('email', 'admin@manzomatech.com')->firstOrFail();
        $company = Company::firstOrFail();
        MetaConnection::create([
            'company_id' => $company->id,
            'connection_method' => 'manual',
            'status' => 'connected',
            'access_token' => 'tenant-token',
        ]);
        Sanctum::actingAs($superAdmin);

        // Without a company_id there is no safe default: it used to silently
        // pick the first company in the table.
        $this->getJson('/api/meta/connection')->assertForbidden();
        $this->deleteJson('/api/meta/connection')->assertForbidden();
        $this->assertDatabaseHas('meta_connections', ['company_id' => $company->id]);

        $this->getJson("/api/meta/connection?company_id={$company->id}")
            ->assertOk()
            ->assertJsonPath('data.company_id', $company->id);
    }

    public function test_a_replayed_lead_webhook_creates_only_one_contact(): void
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

        Http::fake(['*' => Http::response(['field_data' => [
            ['name' => 'email', 'values' => ['lead@example.com']],
            ['name' => 'full_name', 'values' => ['Lead Person']],
        ]])]);

        $leadAds = app(MetaLeadAdsService::class);
        $leadAds->ingest('page-1', 'form-1', 'lead-999');
        $leadAds->ingest('page-1', 'form-1', 'lead-999');

        $this->assertSame(1, CRMContact::where('meta_lead_id', 'lead-999')->count());
    }

    public function test_the_unique_index_stops_a_duplicate_lead_slipping_past_the_check(): void
    {
        $admin = $this->admin();
        CRMContact::create([
            'company_id' => $admin->company_id,
            'name' => 'First',
            'email' => 'first@example.com',
            'type' => 'lead',
            'status' => 'new',
            'currency' => 'EGP',
            'meta_lead_id' => 'lead-777',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        CRMContact::create([
            'company_id' => $admin->company_id,
            'name' => 'Second',
            'email' => 'second@example.com',
            'type' => 'lead',
            'status' => 'new',
            'currency' => 'EGP',
            'meta_lead_id' => 'lead-777',
        ]);
    }

    public function test_webhook_verification_matches_a_company_token_without_scanning_every_row(): void
    {
        $admin = $this->admin();
        config(['meta.webhook_verify_token' => null]);
        $connection = $this->connection($admin, ['webhook_verify_token' => 'company-verify-token']);

        // The token is stored encrypted; the lookup runs on its hash.
        $this->assertSame(
            hash('sha256', 'company-verify-token'),
            $connection->fresh()->webhook_verify_token_hash,
        );

        $this->getJson('/api/meta/webhooks/leadgen?hub_mode=subscribe&hub_verify_token=company-verify-token&hub_challenge=42')
            ->assertOk()
            ->assertSee('42');

        $this->getJson('/api/meta/webhooks/leadgen?hub_mode=subscribe&hub_verify_token=wrong-token&hub_challenge=42')
            ->assertForbidden();
    }

    public function test_webhook_endpoints_are_outside_the_tenant_rate_limiter(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->getName() === 'meta.webhooks.leadgen.receive');

        $middleware = $route->gatherMiddleware();

        // A 429 here means Meta silently drops the lead.
        $this->assertNotContains('throttle:erp-api', $middleware);
        $this->assertContains('throttle:meta-webhooks', $middleware);
    }

    private function connection(User $admin, array $overrides = []): MetaConnection
    {
        return MetaConnection::create(array_merge([
            'company_id' => $admin->company_id,
            'connection_method' => 'manual',
            'status' => 'connected',
            'access_token' => 'test-token',
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

    public function test_no_platform_wide_credentials_exist_to_fall_back_on(): void
    {
        // Each company connects their own app; a shared key would let one
        // tenant's webhook be verified with another's secret.
        $this->assertNull(config('meta.app_id'));
        $this->assertNull(config('meta.app_secret'));
        $this->assertNull(config('meta.webhook_verify_token'));
        $this->assertNull(config('tiktok.app_id'));
        $this->assertNull(config('tiktok.app_secret'));
    }

    public function test_connecting_without_company_credentials_is_refused(): void
    {
        $this->admin();

        $this->getJson('/api/meta/oauth/url')
            ->assertStatus(422)
            ->assertJsonPath('errors.app_id.0', 'Save your Meta App ID and App Secret before connecting.');
    }

    public function test_a_webhook_signed_with_another_companys_secret_is_rejected(): void
    {
        $admin = $this->admin();
        $connection = $this->connection($admin, ['app_id' => 'app-a', 'app_secret' => 'secret-a']);
        MetaLeadFormMapping::create([
            'company_id' => $admin->company_id,
            'meta_connection_id' => $connection->id,
            'page_id' => 'page-a',
            'form_id' => 'form-a',
            'field_mapping' => ['email' => 'email'],
            'is_active' => true,
        ]);

        $payload = ['entry' => [['changes' => [[
            'field' => 'leadgen',
            'value' => ['page_id' => 'page-a', 'form_id' => 'form-a', 'leadgen_id' => 'lead-x'],
        ]]]]];
        $body = json_encode($payload);

        // Signed with a different tenant's secret.
        $this->call('POST', '/api/meta/webhooks/leadgen', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'secret-b'),
        ], $body)->assertForbidden();

        // Signed with the owning company's secret. The queue runs inline in
        // tests, so the lead fetch needs a stub.
        Http::fake(['*' => Http::response(['field_data' => [['name' => 'email', 'values' => ['x@example.com']]]])]);

        $this->call('POST', '/api/meta/webhooks/leadgen', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'secret-a'),
        ], $body)->assertOk();
    }

    public function test_one_company_verify_token_does_not_unlock_another(): void
    {
        $admin = $this->admin();
        $this->connection($admin, ['webhook_verify_token' => 'ours']);

        $other = Company::factory()->create();
        MetaConnection::create([
            'company_id' => $other->id,
            'connection_method' => 'oauth',
            'status' => 'connected',
            'webhook_verify_token' => 'theirs',
        ]);

        // Both are valid tokens for their own tenant, and nothing else is.
        $this->getJson('/api/meta/webhooks/leadgen?hub_mode=subscribe&hub_verify_token=ours&hub_challenge=1')->assertOk();
        $this->getJson('/api/meta/webhooks/leadgen?hub_mode=subscribe&hub_verify_token=theirs&hub_challenge=1')->assertOk();
        $this->getJson('/api/meta/webhooks/leadgen?hub_mode=subscribe&hub_verify_token=guessed&hub_challenge=1')->assertForbidden();
    }
}
