<?php

namespace App\Modules\MetaIntegration\Console;

use App\Modules\MetaIntegration\Jobs\SyncMetaAudienceJob;
use App\Modules\MetaIntegration\Models\MetaAudienceSync;
use Illuminate\Console\Command;

class SyncMetaAudiences extends Command
{
    protected $signature = 'meta:sync-audiences';

    protected $description = 'Dispatch scheduled Meta Custom Audience re-syncs that are due';

    private const FREQUENCY_HOURS = [
        'hourly' => 1,
        'daily' => 24,
        'weekly' => 24 * 7,
    ];

    public function handle(): int
    {
        $count = 0;

        MetaAudienceSync::where('sync_mode', 'scheduled')->chunkById(200, function ($syncs) use (&$count) {
            foreach ($syncs as $sync) {
                $hours = self::FREQUENCY_HOURS[$sync->schedule_frequency] ?? 24;
                if (! $sync->last_synced_at || $sync->last_synced_at->lte(now()->subHours($hours))) {
                    SyncMetaAudienceJob::dispatch($sync->id)->onQueue('meta-events');
                    $count++;
                }
            }
        });

        $this->info("Dispatched {$count} Meta audience syncs.");

        return self::SUCCESS;
    }
}
