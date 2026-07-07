<?php

namespace App\Modules\MetaIntegration\Console;

use App\Modules\MetaIntegration\Jobs\SendMetaConversionEvent;
use App\Modules\MetaIntegration\Models\MetaEventLog;
use Illuminate\Console\Command;

class RetryMetaEvents extends Command
{
    protected $signature = 'meta:retry-events';

    protected $description = 'Requeue Meta Conversions API events eligible for retry';

    public function handle(): int
    {
        $count = 0;

        MetaEventLog::whereIn('status', ['pending', 'failed'])
            ->where(function ($query) {
                $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            })
            ->chunkById(500, function ($logs) use (&$count) {
                foreach ($logs as $log) {
                    SendMetaConversionEvent::dispatch($log->id)->onQueue('meta-events');
                    $count++;
                }
            });

        $this->info("Requeued {$count} Meta events.");

        return self::SUCCESS;
    }
}
