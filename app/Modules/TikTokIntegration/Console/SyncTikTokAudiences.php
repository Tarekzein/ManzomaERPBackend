<?php

namespace App\Modules\TikTokIntegration\Console;

use App\Modules\TikTokIntegration\Models\TikTokAudienceSync;
use App\Modules\TikTokIntegration\Services\TikTokAudienceService;
use Illuminate\Console\Command;

class SyncTikTokAudiences extends Command
{
    protected $signature = 'tiktok:sync-audiences';

    protected $description = 'Dispatch scheduled TikTok Custom Audience re-syncs that are due';

    public function handle(TikTokAudienceService $audiences): int
    {
        $due = 0;

        TikTokAudienceSync::query()
            ->where('sync_mode', 'scheduled')
            ->whereHas('connection', fn ($query) => $query->where('status', 'connected'))
            ->whereNotIn('status', ['queued', 'processing'])
            ->chunkById(200, function ($syncs) use (&$due, $audiences) {
                foreach ($syncs as $sync) {
                    if ($this->isDue($sync)) {
                        $audiences->queueSync($sync);
                        $due++;
                    }
                }
            });

        $this->info("Dispatched {$due} TikTok audience sync(s).");

        return self::SUCCESS;
    }

    private function isDue(TikTokAudienceSync $sync): bool
    {
        if (! $sync->last_synced_at) {
            return true;
        }

        return match ($sync->schedule_frequency) {
            'hourly' => $sync->last_synced_at->addHour()->isPast(),
            'weekly' => $sync->last_synced_at->addWeek()->isPast(),
            default => $sync->last_synced_at->addDay()->isPast(),
        };
    }
}
