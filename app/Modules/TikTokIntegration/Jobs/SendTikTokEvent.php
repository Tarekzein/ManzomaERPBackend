<?php

namespace App\Modules\TikTokIntegration\Jobs;

use App\Modules\TikTokIntegration\Exceptions\TikTokApiException;
use App\Modules\TikTokIntegration\Models\TikTokEventLog;
use App\Modules\TikTokIntegration\Services\TikTokEventService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Delivers one conversion event. Retryable API faults are rescheduled with the
 * configured backoff; anything else is dead-lettered on the log so it is
 * visible rather than lost.
 */
class SendTikTokEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // Retries are scheduled through the log, not the queue.

    public int $timeout = 60;

    public function __construct(private readonly int $logId) {}

    public function handle(TikTokEventService $events): void
    {
        $log = TikTokEventLog::with('connection')->find($this->logId);

        if (! $log || $log->status === 'sent' || ! $log->connection) {
            return;
        }

        try {
            $events->send($log);
        } catch (TikTokApiException $exception) {
            $this->recordFailure($log, $exception);
        }
    }

    private function recordFailure(TikTokEventLog $log, TikTokApiException $exception): void
    {
        $attempts = (int) $log->attempts + 1;
        $backoff = (array) config('tiktok.retry_backoff_seconds', [30, 120, 600]);
        $maxAttempts = (int) config('tiktok.max_retry_attempts', 5);
        $retryable = $exception->isRetryable() && $attempts < $maxAttempts;

        $log->forceFill([
            'attempts' => $attempts,
            'status' => $retryable ? 'pending' : 'failed',
            'next_retry_at' => $retryable
                ? now()->addSeconds($backoff[min($attempts - 1, count($backoff) - 1)])
                : null,
            'error' => $exception->getMessage(),
        ])->save();

        Log::warning('[tiktok] conversion event delivery failed', [
            'log_id' => $log->id,
            'company_id' => $log->company_id,
            'attempts' => $attempts,
            'retrying' => $retryable,
            'api_code' => $exception->apiCode(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[tiktok] conversion event job failed', [
            'log_id' => $this->logId,
            'message' => $exception->getMessage(),
        ]);
    }
}
