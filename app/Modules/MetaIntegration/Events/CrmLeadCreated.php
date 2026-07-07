<?php

namespace App\Modules\MetaIntegration\Events;

use App\Modules\CRM\Models\CRMContact;
use Illuminate\Foundation\Events\Dispatchable;

class CrmLeadCreated
{
    use Dispatchable;

    public function __construct(public readonly CRMContact $contact) {}
}
