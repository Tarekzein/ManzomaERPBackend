<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CRMPipelineStage;

class CRMSetupService
{
    public const DEFAULT_STAGES = [
        ['name' => 'Lead', 'key' => 'lead', 'sort_order' => 10, 'probability' => 10],
        ['name' => 'Qualified', 'key' => 'qualified', 'sort_order' => 20, 'probability' => 30],
        ['name' => 'Proposal', 'key' => 'proposal', 'sort_order' => 30, 'probability' => 55],
        ['name' => 'Negotiation', 'key' => 'negotiation', 'sort_order' => 40, 'probability' => 75],
        ['name' => 'Won', 'key' => 'won', 'sort_order' => 50, 'probability' => 100, 'is_won' => true],
        ['name' => 'Lost', 'key' => 'lost', 'sort_order' => 60, 'probability' => 0, 'is_lost' => true],
    ];

    public function ensureDefaultStages(int $companyId): void
    {
        foreach (self::DEFAULT_STAGES as $stage) {
            CRMPipelineStage::firstOrCreate(
                ['company_id' => $companyId, 'key' => $stage['key']],
                $stage + ['company_id' => $companyId, 'is_won' => $stage['is_won'] ?? false, 'is_lost' => $stage['is_lost'] ?? false],
            );
        }
    }
}
