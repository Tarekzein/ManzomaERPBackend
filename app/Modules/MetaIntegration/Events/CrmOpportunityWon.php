<?php

namespace App\Modules\MetaIntegration\Events;

use App\Modules\CRM\Models\CRMOpportunity;
use Illuminate\Foundation\Events\Dispatchable;

class CrmOpportunityWon
{
    use Dispatchable;

    public function __construct(public readonly CRMOpportunity $opportunity) {}
}
