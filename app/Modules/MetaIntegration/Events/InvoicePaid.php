<?php

namespace App\Modules\MetaIntegration\Events;

use App\Modules\Finance\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

class InvoicePaid
{
    use Dispatchable;

    public function __construct(public readonly Invoice $invoice) {}
}
