<?php

namespace App\Modules\MetaIntegration\Jobs;

use App\Modules\MetaIntegration\Exceptions\MetaGraphException;
use App\Modules\MetaIntegration\Models\MetaEventLog;
use App\Modules\MetaIntegration\Services\MetaGraphClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendMetaConversionEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(private readonly int $logId) {}

    public function handle(): void
    {
        $log = MetaEventLog::with('connection')->find($this->logId);
        if (! $log || $log->status === 'sent') {
            return;
        }

        $connection = $log->connection;
        if (! $connection || ! $connection->isConnected()) {
            $log->update(['status' => 'failed']);

            return;
        }

        $client = new MetaGraphClient($connection);

        try {
            $response = $client->post("{$connection->pixel_id}/events", [
                'data' => json_encode([$log->payload]),
                'test_event_code' => $log->payload['test_event_code'] ?? null,
            ]);

            $log->update([
                'status' => 'sent',
                'http_status' => 200,
                'response_body' => $response,
                'sent_at' => now(),
            ]);

            $connection->update(['last_health_check_at' => now(), 'last_error' => null]);
        } catch (MetaGraphException $e) {
            $this->handleFailure($log, $connection, $e);
        }
    }

    private function handleFailure(MetaEventLog $log, $connection, MetaGraphException $e): void
    {
        $attempts = $log->attempts + 1;
        $maxAttempts = config('meta.max_retry_attempts', 5);
        $backoff = config('meta.retry_backoff_seconds', [30, 120, 600, 1800, 3600]);

        if ($e->isAuthFailure()) {
            $connection->update(['status' => 'error', 'last_error' => $e->getMessage()]);
        }

        if ($e->isRetryable() && $attempts < $maxAttempts) {
            $log->update([
                'status' => 'pending',
                'http_status' => $e->httpStatus(),
                'attempts' => $attempts,
                'next_retry_at' => now()->addSeconds($backoff[$attempts - 1] ?? end($backoff)),
                'response_body' => ['error' => $e->getMessage(), 'fbtrace_id' => $e->fbtraceId()],
            ]);

            return;
        }

        $log->update([
            'status' => 'dead_letter',
            'http_status' => $e->httpStatus(),
            'attempts' => $attempts,
            'response_body' => ['error' => $e->getMessage(), 'fbtrace_id' => $e->fbtraceId()],
        ]);
    }
}
