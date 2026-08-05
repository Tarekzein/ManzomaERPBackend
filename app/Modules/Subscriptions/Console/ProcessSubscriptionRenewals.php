<?php

namespace App\Modules\Subscriptions\Console;

use App\Modules\Subscriptions\Services\SubscriptionRenewalService;
use Illuminate\Console\Command;

class ProcessSubscriptionRenewals extends Command
{
    protected $signature = 'subscriptions:process-renewals {--limit= : Maximum subscriptions to process in this run}';

    protected $description = 'Charge, invoice or expire subscriptions whose billing period is ending';

    public function handle(SubscriptionRenewalService $renewals): int
    {
        $limit = $this->option('limit') !== null ? max((int) $this->option('limit'), 1) : null;
        $stats = $renewals->processDue($limit);

        foreach ($stats as $outcome => $count) {
            if ($count > 0) {
                $this->line(str_pad($outcome, 16).$count);
            }
        }

        $this->info('Subscription renewals processed.');

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
