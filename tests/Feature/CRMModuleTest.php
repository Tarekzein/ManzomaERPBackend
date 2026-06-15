<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\CRM\Models\CRMCampaign;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\CRM\Models\CRMPipelineStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CRMModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_crm_contact_pipeline_tasks_segments_campaigns_reports_and_dashboard_work(): void
    {
        $admin = $this->admin();
        $baselineConverted = $this->getJson('/api/crm/reports/conversion-rate')->assertOk()->json('data.converted');
        $baselineOpenOpportunities = $this->getJson('/api/dashboard')->assertOk()->json('data.metrics.open_opportunities');

        $tag = $this->postJson('/api/crm/tags', ['name' => 'Test Enterprise', 'color' => '#2ec27e'])
            ->assertCreated()
            ->json('data');

        $contact = $this->postJson('/api/crm/contacts', [
            'owner_id' => $admin->id,
            'type' => 'lead',
            'status' => 'new',
            'name' => 'Nour Hassan',
            'company_name' => 'Nour Retail',
            'email' => 'nour@example.com',
            'phone' => '+201000000000',
            'region' => 'Cairo',
            'source' => 'Website',
            'currency' => 'EGP',
            'custom_attributes' => ['industry' => 'Retail'],
            'tag_ids' => [$tag['id']],
        ])->assertCreated()->assertJsonPath('data.tags.0.name', 'Test Enterprise')->json('data');

        $this->postJson("/api/crm/contacts/{$contact['id']}/convert")
            ->assertOk()
            ->assertJsonPath('data.type', 'customer')
            ->assertJsonPath('data.sales_contact.type', 'customer');
        $this->assertDatabaseHas('sales_contacts', ['company_id' => $admin->company_id, 'email' => 'nour@example.com']);

        $stages = $this->getJson('/api/crm/pipeline-stages')->assertOk()->json('data');
        $qualified = collect($stages)->firstWhere('key', 'qualified');
        $won = collect($stages)->firstWhere('key', 'won');

        $opportunity = $this->postJson('/api/crm/opportunities', [
            'contact_id' => $contact['id'],
            'stage_id' => $qualified['id'],
            'owner_id' => $admin->id,
            'title' => 'ERP rollout',
            'value' => 250000,
            'currency' => 'EGP',
            'expected_close_date' => '2026-08-31',
        ])->assertCreated()->assertJsonPath('data.status', 'open')->json('data');

        $this->postJson("/api/crm/opportunities/{$opportunity['id']}/move", ['stage_id' => $won['id']])
            ->assertOk()
            ->assertJsonPath('data.status', 'won');

        $this->postJson('/api/crm/activities', [
            'contact_id' => $contact['id'],
            'opportunity_id' => $opportunity['id'],
            'type' => 'call',
            'subject' => 'Discovery call',
            'body' => 'Qualified implementation needs.',
            'occurred_at' => '2026-06-14 10:00:00',
        ])->assertCreated()->assertJsonPath('data.subject', 'Discovery call');

        $task = $this->postJson('/api/crm/tasks', [
            'contact_id' => $contact['id'],
            'opportunity_id' => $opportunity['id'],
            'assignee_id' => $admin->id,
            'title' => 'Send proposal',
            'priority' => 'high',
            'due_at' => '2026-06-13 10:00:00',
        ])->assertCreated()->assertJsonPath('data.status', 'open')->json('data');
        $this->postJson("/api/crm/tasks/{$task['id']}/complete")->assertOk()->assertJsonPath('data.status', 'completed');

        $segment = $this->postJson('/api/crm/segments', [
            'name' => 'Cairo enterprise leads',
            'criteria' => ['regions' => ['Cairo'], 'tag_ids' => [$tag['id']]],
        ])->assertCreated()->json('data');
        $this->getJson("/api/crm/segments/{$segment['id']}/contacts")
            ->assertOk()
            ->assertJsonPath('data.0.email', 'nour@example.com');

        $campaign = $this->postJson('/api/crm/campaigns', [
            'segment_id' => $segment['id'],
            'provider' => 'sendgrid',
            'external_id' => 'sg-campaign-1',
            'name' => 'Implementation launch',
            'subject' => 'ERP implementation offer',
            'content' => 'Let us talk.',
            'status' => 'sent',
            'sent_at' => '2026-06-14 11:00:00',
        ])->assertCreated()->json('data');
        $this->postJson('/api/crm/campaign-webhooks/sendgrid', [
            'campaign_id' => $campaign['id'],
            'email' => 'nour@example.com',
            'event_type' => 'open',
            'payload' => ['message_id' => 'abc'],
        ])->assertCreated()->assertJsonPath('data.event_type', 'open');
        $this->deleteJson("/api/crm/campaigns/{$campaign['id']}")->assertOk();

        $this->getJson('/api/crm/reports/conversion-rate')->assertOk()->assertJsonPath('data.converted', $baselineConverted + 1);
        $this->assertTrue(collect($this->getJson('/api/crm/reports/pipeline-value')->assertOk()->json('data'))->contains(fn (array $row) => (float) $row['value'] >= 250000));
        $this->assertTrue(collect($this->getJson('/api/crm/reports/rep-performance')->assertOk()->json('data'))->contains(fn (array $row) => (float) $row['won_value'] >= 250000));
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.metrics.open_opportunities', $baselineOpenOpportunities);
    }

    public function test_company_users_cannot_access_other_company_crm_records(): void
    {
        $admin = $this->admin();
        $otherUser = User::factory()->create(['company_id' => null]);
        $contact = CRMContact::create(['company_id' => $admin->company_id, 'type' => 'lead', 'name' => 'Scoped Lead']);
        CRMPipelineStage::create(['company_id' => $admin->company_id, 'name' => 'Custom', 'key' => 'custom']);

        $this->putJson("/api/crm/contacts/{$contact->id}", [
            'type' => 'lead',
            'name' => 'Cross Company',
        ])->assertOk();

        Sanctum::actingAs($otherUser);
        $this->getJson('/api/crm/contacts')->assertForbidden();
    }

    private function admin(): User
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin);

        return $admin;
    }
}
