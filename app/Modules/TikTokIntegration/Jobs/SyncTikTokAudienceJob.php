<?php

namespace App\Modules\TikTokIntegration\Jobs;

use App\Modules\TikTokIntegration\Models\TikTokAudienceSync;
use App\Modules\TikTokIntegration\Services\TikTokAudienceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncTikTokAudienceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    /** Uploads can be large; give them room. */
    public int $timeout = 300;

    public function __construct(private readonly int $syncId) {}

    public function handle(TikTokAudienceService $audiences): void
    {
        $sync = TikTokAudienceSync::with('connection', 'segment')->find($this->syncId);

        if (! $sync) {
            return;
        }

        if (! $sync->connection?->isConnected()) {
            $sync->forceFill([
                'status' => 'failed',
                'last_error' => 'Reconnect TikTok before syncing this audience.',
            ])->save();

            return;
        }

        $audiences->sync($sync);
    }

    public function failed(\Throwable $exception): void
    {
        TikTokAudienceSync::whereKey($this->syncId)->update([
            'status' => 'failed',
            'last_error' => $exception->getMessage(),
        ]);

        Log::error('[tiktok] audience sync job failed', [
            'sync_id' => $this->syncId,
            'message' => $exception->getMessage(),
        ]);
    }
}
