<?php

namespace Database\Seeders;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\CRM\Models\CRMActivity;
use App\Modules\CRM\Models\CRMCampaign;
use App\Modules\CRM\Models\CRMCampaignEvent;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\CRM\Models\CRMOpportunity;
use App\Modules\CRM\Models\CRMPipelineStage;
use App\Modules\CRM\Models\CRMSegment;
use App\Modules\CRM\Models\CRMTag;
use App\Modules\CRM\Models\CRMTask;
use App\Modules\CRM\Services\CRMSetupService;
use App\Modules\Sales\Models\SalesContact;
use Illuminate\Database\Seeder;

class CRMSeeder extends Seeder
{
    public function run(): void
    {
        $setup = app(CRMSetupService::class);
        Company::query()->where('is_active', true)->each(function (Company $company) use ($setup): void {
            $setup->ensureDefaultStages($company->id);
            $this->seedCompany($company);
        });
    }

    private function seedCompany(Company $company): void
    {
        $owner = User::where('company_id', $company->id)->first();
        $salesCustomer = SalesContact::where('company_id', $company->id)->where('email', 'customer@seed.example')->first();

        $tags = collect([
            ['name' => 'Enterprise', 'color' => '#2ec27e'],
            ['name' => 'High Intent', 'color' => '#f5c211'],
            ['name' => 'Retail', 'color' => '#3584e4'],
        ])->map(fn (array $tag) => CRMTag::updateOrCreate(['company_id' => $company->id, 'name' => $tag['name']], $tag));

        $contacts = collect([
            ['email' => 'crm.customer@seed.example', 'type' => 'customer', 'status' => 'converted', 'name' => 'Mariam Adel', 'company_name' => 'Nile Retail Group', 'region' => 'Cairo', 'source' => 'Referral', 'sales_contact_id' => $salesCustomer?->id, 'converted_at' => now()->subDays(14), 'tag_names' => ['Enterprise', 'Retail']],
            ['email' => 'crm.prospect@seed.example', 'type' => 'prospect', 'status' => 'qualified', 'name' => 'Omar Samir', 'company_name' => 'Alexandria Logistics', 'region' => 'Alexandria', 'source' => 'Website', 'tag_names' => ['High Intent']],
            ['email' => 'crm.lead@seed.example', 'type' => 'lead', 'status' => 'new', 'name' => 'Salma Tarek', 'company_name' => 'Delta Distribution', 'region' => 'Mansoura', 'source' => 'Campaign', 'tag_names' => ['Retail']],
        ])->map(function (array $data) use ($company, $owner, $tags) {
            $tagNames = $data['tag_names'];
            unset($data['tag_names']);
            $contact = CRMContact::updateOrCreate(
                ['company_id' => $company->id, 'email' => $data['email']],
                $data + ['owner_id' => $owner?->id, 'phone' => '+201000000303', 'currency' => $company->currency, 'custom_attributes' => ['industry' => 'Retail', 'company_size' => '50-200']],
            );
            $contact->tags()->sync($tags->whereIn('name', $tagNames)->pluck('id'));

            return $contact;
        });

        $stages = CRMPipelineStage::where('company_id', $company->id)->get()->keyBy('key');
        $opportunities = collect([
            ['title' => 'Nile Retail ERP Expansion', 'contact' => $contacts[0], 'stage' => 'won', 'value' => 175000, 'status' => 'won', 'won_at' => now()->subDays(7)],
            ['title' => 'Alexandria Logistics Rollout', 'contact' => $contacts[1], 'stage' => 'proposal', 'value' => 240000, 'status' => 'open'],
            ['title' => 'Delta Distribution Discovery', 'contact' => $contacts[2], 'stage' => 'lead', 'value' => 90000, 'status' => 'open'],
        ])->map(fn (array $data) => CRMOpportunity::updateOrCreate(
            ['company_id' => $company->id, 'title' => $data['title']],
            ['contact_id' => $data['contact']->id, 'stage_id' => $stages[$data['stage']]->id, 'owner_id' => $owner?->id, 'value' => $data['value'], 'currency' => $company->currency, 'expected_close_date' => now()->addDays(45), 'probability' => $stages[$data['stage']]->probability, 'status' => $data['status'], 'won_at' => $data['won_at'] ?? null, 'notes' => 'Seeded CRM opportunity.'],
        ));

        foreach ([
            ['subject' => 'Discovery call with Alexandria Logistics', 'type' => 'call', 'contact' => $contacts[1], 'opportunity' => $opportunities[1]],
            ['subject' => 'Sent ERP capabilities email', 'type' => 'email', 'contact' => $contacts[2], 'opportunity' => $opportunities[2]],
            ['subject' => 'Quarterly customer review', 'type' => 'meeting', 'contact' => $contacts[0], 'opportunity' => $opportunities[0]],
        ] as $activity) {
            CRMActivity::updateOrCreate(
                ['company_id' => $company->id, 'subject' => $activity['subject']],
                ['contact_id' => $activity['contact']->id, 'opportunity_id' => $activity['opportunity']->id, 'user_id' => $owner?->id, 'type' => $activity['type'], 'body' => 'Seeded CRM activity.', 'occurred_at' => now()->subDays(2)],
            );
        }

        foreach ([
            ['title' => 'Follow up on logistics proposal', 'contact' => $contacts[1], 'opportunity' => $opportunities[1], 'priority' => 'high', 'due_at' => now()->addDays(2)],
            ['title' => 'Schedule Delta discovery workshop', 'contact' => $contacts[2], 'opportunity' => $opportunities[2], 'priority' => 'normal', 'due_at' => now()->addDays(5)],
        ] as $task) {
            CRMTask::updateOrCreate(
                ['company_id' => $company->id, 'title' => $task['title']],
                ['contact_id' => $task['contact']->id, 'opportunity_id' => $task['opportunity']->id, 'assignee_id' => $owner?->id, 'created_by' => $owner?->id, 'priority' => $task['priority'], 'status' => 'open', 'due_at' => $task['due_at'], 'reminder_at' => $task['due_at']->copy()->subDay(), 'notes' => 'Seeded follow-up task.'],
            );
        }

        $segment = CRMSegment::updateOrCreate(
            ['company_id' => $company->id, 'name' => 'High Intent Prospects'],
            ['criteria' => ['types' => ['prospect'], 'tag_ids' => [$tags->firstWhere('name', 'High Intent')->id]]],
        );
        $campaign = CRMCampaign::updateOrCreate(
            ['company_id' => $company->id, 'external_id' => 'seed-crm-campaign-001'],
            ['segment_id' => $segment->id, 'provider' => 'sendgrid', 'name' => 'ERP Growth Campaign', 'subject' => 'Scale operations with ManzomaERP', 'content' => 'Seeded campaign content.', 'status' => 'sent', 'sent_at' => now()->subDay()],
        );
        CRMCampaignEvent::updateOrCreate(
            ['campaign_id' => $campaign->id, 'contact_id' => $contacts[1]->id, 'event_type' => 'open'],
            ['provider' => 'sendgrid', 'payload' => ['seeded' => true], 'occurred_at' => now()->subHours(12)],
        );
    }
}
