<?php

namespace App\Modules\POS\Console;

use App\Modules\POS\Jobs\ProcessPosOutboxEvent;
use App\Modules\POS\Models\PosOutboxEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchPosOutbox extends Command
{
    protected $signature = 'pos:dispatch-outbox {--limit=500 : Maximum due events to queue}';

    protected $description = 'Queue committed POS events for integrations and company webhooks';

    public function handle(): int
    {
        $limit = min(max((int) $this->option('limit'), 1), 5000);
        $ids = DB::transaction(function () use ($limit) {
            $ids = PosOutboxEvent::query()
                ->whereNull('processed_at')
                ->whereNull('failed_at')
                ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                PosOutboxEvent::query()->whereIn('id', $ids)->update([
                    // Dispatch lease: if queueing is interrupted, the event is
                    // selected again after five minutes instead of being lost.
                    'available_at' => now()->addMinutes(5),
                    'updated_at' => now(),
                ]);
            }

            return $ids;
        });

        $ids->each(function (int $eventId): void {
            ProcessPosOutboxEvent::dispatch($eventId);
        });

        $this->info("Queued {$ids->count()} POS outbox events.");

        return self::SUCCESS;
    }
}
