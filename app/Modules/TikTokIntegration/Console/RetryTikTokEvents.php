<?php

namespace App\Modules\TikTokIntegration\Console;

use App\Modules\TikTokIntegration\Jobs\SendTikTokEvent;
use App\Modules\TikTokIntegration\Models\TikTokEventLog;
use Illuminate\Console\Command;

class RetryTikTokEvents extends Command
{
    protected $signature = 'tiktok:retry-events';

    protected $description = 'Requeue TikTok conversion events that are due for another attempt';

    public function handle(): int
    {
        $count = 0;

        TikTokEventLog::where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
            ->chunkById(500, function ($logs) use (&$count) {
                foreach ($logs as $log) {
                    SendTikTokEvent::dispatch($log->id)->onQueue('tiktok-events');
                    $count++;
                }
            });

        $this->info("Requeued {$count} TikTok events.");

        return self::SUCCESS;
    }
}
