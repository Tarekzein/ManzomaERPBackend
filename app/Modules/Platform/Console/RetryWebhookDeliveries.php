<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Jobs\DeliverWebhook;
use App\Modules\Platform\Models\WebhookDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RetryWebhookDeliveries extends Command
{
    protected $signature = 'platform:retry-webhooks {--limit=500 : Maximum due deliveries to queue}';

    protected $description = 'Queue pending and retryable company webhook deliveries';

    public function handle(): int
    {
        $limit = min(max((int) $this->option('limit'), 1), 5000);
        $ids = DB::transaction(function () use ($limit) {
            $ids = WebhookDelivery::query()
                ->whereIn('status', ['pending', 'failed', 'processing', 'queued'])
                ->where('attempts', '<', 5)
                ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->whereHas('endpoint', fn ($query) => $query->where('is_active', true))
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                WebhookDelivery::query()->whereIn('id', $ids)->update([
                    'status' => 'queued',
                    // A lost queue message becomes eligible again after this
                    // lease, while subsequent scheduler passes reach later rows.
                    'next_attempt_at' => now()->addMinutes(5),
                    'updated_at' => now(),
                ]);
            }

            return $ids;
        });

        $ids->each(fn (int $id) => DeliverWebhook::dispatch($id));
        $this->info("Queued {$ids->count()} webhook deliveries.");

        return self::SUCCESS;
    }
}
