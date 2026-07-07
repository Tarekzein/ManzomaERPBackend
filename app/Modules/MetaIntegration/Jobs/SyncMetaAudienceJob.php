<?php

namespace App\Modules\MetaIntegration\Jobs;

use App\Modules\MetaIntegration\Exceptions\MetaGraphException;
use App\Modules\MetaIntegration\Models\MetaAudienceSync;
use App\Modules\MetaIntegration\Services\MetaAudienceService;
use App\Modules\MetaIntegration\Services\MetaGraphClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncMetaAudienceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(private readonly int $syncId) {}

    public function handle(MetaAudienceService $audiences): void
    {
        $sync = MetaAudienceSync::with('connection', 'segment')->find($this->syncId);
        if (! $sync || ! $sync->connection?->isConnected() || ! $sync->segment) {
            return;
        }

        if (! $sync->meta_audience_id) {
            $sync = $audiences->createAudience($sync);
        }

        $sync->update(['status' => 'syncing']);
        $client = new MetaGraphClient($sync->connection);
        $batchSize = config('meta.audience_batch_size', 1000);

        try {
            $audiences->segmentContactsQuery($sync->company_id, $sync->segment->criteria)
                ->chunk($batchSize, function ($contacts) use ($audiences, $client, $sync) {
                    $rows = $contacts
                        ->map(fn ($contact) => $audiences->hashedIdentifiers($contact))
                        ->filter()
                        ->values()
                        ->all();

                    if ($rows) {
                        $client->post("{$sync->meta_audience_id}/users", [
                            'payload' => json_encode([
                                'schema' => ['EMAIL', 'PHONE'],
                                'data' => $rows,
                            ]),
                        ]);
                    }
                });

            $status = $client->get($sync->meta_audience_id, [
                'fields' => 'approximate_count_lower_bound,approximate_count_upper_bound',
            ]);

            $sync->update([
                'status' => 'synced',
                'approximate_count' => $status['approximate_count_upper_bound'] ?? $status['approximate_count_lower_bound'] ?? null,
                'last_synced_at' => now(),
                'last_error' => null,
            ]);
        } catch (MetaGraphException $e) {
            $sync->update(['status' => 'error', 'last_error' => $e->getMessage()]);
        }
    }
}
