<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\CRM\Models\CRMOpportunity;
use App\Modules\CRM\Models\CRMPipelineStage;
use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\TikTokIntegration\Models\TikTokConnection;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cross-module social reporting: leads, campaigns and revenue attribution
 * assembled from what the system already stores.
 */
class SocialInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_leads_are_split_by_platform(): void
    {
        $admin = $this->admin();
        $this->lead($admin, ['meta_lead_id' => 'l1', 'meta_platform' => 'facebook', 'meta_campaign_id' => 'camp-a']);
        $this->lead($admin, ['meta_lead_id' => 'l2', 'meta_platform' => 'instagram', 'meta_campaign_id' => 'camp-a']);
        $this->lead($admin, ['tiktok_lead_id' => 't1', 'tiktok_campaign_id' => 'tt-1']);
        // A contact created by hand should not count as a social lead.
        $this->lead($admin, []);

        $data = $this->getJson('/api/social/insights')->assertOk()->json('data');

        $this->assertSame(3, $data['leads']['total']);
        $this->assertSame(1, $data['leads']['by_platform']['facebook']);
        $this->assertSame(1, $data['leads']['by_platform']['instagram']);
        $this->assertSame(1, $data['leads']['by_platform']['tiktok']);
        // Seeded demo contacts share the window, so only assert the direction.
        $this->assertGreaterThan(0, $data['leads']['share_of_new_contacts']);
    }

    public function test_campaigns_are_ranked_across_both_platforms(): void
    {
        $admin = $this->admin();
        foreach (range(1, 3) as $index) {
            $this->lead($admin, ['meta_lead_id' => "m{$index}", 'meta_campaign_id' => 'camp-busy']);
        }
        $this->lead($admin, ['tiktok_lead_id' => 't1', 'tiktok_campaign_id' => 'tt-quiet']);

        $campaigns = $this->getJson('/api/social/insights')->assertOk()->json('data.campaigns');

        $this->assertSame('camp-busy', $campaigns[0]['campaign_id']);
        $this->assertSame('meta', $campaigns[0]['platform']);
        $this->assertSame(3, $campaigns[0]['leads']);
        $this->assertSame('tiktok', $campaigns[1]['platform']);
    }

    public function test_revenue_is_attributed_to_socially_sourced_opportunities(): void
    {
        $admin = $this->admin();
        $social = $this->lead($admin, ['meta_lead_id' => 'l1', 'meta_campaign_id' => 'camp-a']);
        $organic = $this->lead($admin, []);

        $this->opportunity($admin, $social, 'won', 5000);
        $this->opportunity($admin, $social, 'open', 2000);
        // Not attributable to social: must stay out of the numbers.
        $this->opportunity($admin, $organic, 'won', 9999);

        $pipeline = $this->getJson('/api/social/insights')->assertOk()->json('data.pipeline');

        $this->assertSame(2, $pipeline['opportunities']);
        $this->assertEquals(5000, $pipeline['won_value']);
        $this->assertEquals(2000, $pipeline['open_value']);
        $this->assertEquals(50, $pipeline['conversion_rate']);
    }

    public function test_connection_health_is_reported_for_both_platforms(): void
    {
        $admin = $this->admin();
        MetaConnection::create([
            'company_id' => $admin->company_id,
            'connection_method' => 'oauth',
            'status' => 'degraded',
            'access_token' => 'token',
        ]);

        $connections = $this->getJson('/api/social/insights')->assertOk()->json('data.connections');

        $this->assertSame('degraded', $connections['meta']['status']);
        $this->assertFalse($connections['meta']['healthy']);
        // Never connected is a distinct state from broken.
        $this->assertSame('not_connected', $connections['tiktok']['status']);
    }

    public function test_insights_never_include_another_company(): void
    {
        $admin = $this->admin();
        $this->lead($admin, ['meta_lead_id' => 'ours']);

        $other = Company::factory()->create();
        CRMContact::create([
            'company_id' => $other->id,
            'name' => 'Their Lead',
            'type' => 'lead',
            'status' => 'new',
            'currency' => 'EGP',
            'meta_lead_id' => 'theirs',
            'meta_campaign_id' => 'their-campaign',
        ]);
        TikTokConnection::create(['company_id' => $other->id, 'status' => 'connected', 'access_token' => 'x']);

        $data = $this->getJson('/api/social/insights')->assertOk()->json('data');

        $this->assertSame(1, $data['leads']['total']);
        $this->assertSame('not_connected', $data['connections']['tiktok']['status']);
        $this->assertStringNotContainsString('their-campaign', json_encode($data));
    }

    public function test_campaign_drilldown_lists_its_leads_with_opportunity_value(): void
    {
        $admin = $this->admin();
        $lead = $this->lead($admin, ['meta_lead_id' => 'l1', 'meta_campaign_id' => 'camp-a', 'name' => 'Camp Lead']);
        $this->opportunity($admin, $lead, 'open', 1500);

        $leads = $this->getJson('/api/social/campaigns/meta/camp-a/leads')->assertOk()->json('data');

        $this->assertCount(1, $leads);
        $this->assertSame('Camp Lead', $leads[0]['name']);
        $this->assertEquals(1500, $leads[0]['opportunity_value']);
    }

    public function test_a_user_without_crm_access_is_refused(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = User::factory()->create(['company_id' => Company::firstOrFail()->id, 'is_active' => true]);
        $employee->assignRole('Employee');
        Sanctum::actingAs($employee);

        $this->getJson('/api/social/insights')->assertForbidden();
    }

    private function lead(User $admin, array $attributes): CRMContact
    {
        Cache::flush(); // the summary is cached per company/window

        return CRMContact::create(array_merge([
            'company_id' => $admin->company_id,
            'name' => 'Lead '.uniqid(),
            'type' => 'lead',
            'status' => 'new',
            'currency' => 'EGP',
        ], $attributes));
    }

    private function opportunity(User $admin, CRMContact $contact, string $status, float $value): CRMOpportunity
    {
        $stage = CRMPipelineStage::firstOrCreate(
            ['company_id' => $admin->company_id, 'key' => 'qualification'],
            ['name' => 'Qualification', 'sort_order' => 1, 'probability' => 20],
        );

        return CRMOpportunity::create([
            'company_id' => $admin->company_id,
            'contact_id' => $contact->id,
            'stage_id' => $stage->id,
            'title' => 'Deal '.uniqid(),
            'value' => $value,
            'currency' => 'EGP',
            'status' => $status,
        ]);
    }

    private function admin(): User
    {
        $this->seed(DatabaseSeeder::class);
        Cache::flush();
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin);

        return $admin;
    }
}
