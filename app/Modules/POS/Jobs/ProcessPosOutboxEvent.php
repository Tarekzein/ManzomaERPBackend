<?php

namespace App\Modules\POS\Jobs;

use App\Modules\MetaIntegration\Events\InvoicePaid;
use App\Modules\Platform\Services\WebhookService;
use App\Modules\POS\Models\PosOutboxEvent;
use App\Modules\POS\Models\PosReturn;
use App\Modules\POS\Models\PosSale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Fan a committed POS transaction out to durable integration records.
 *
 * This job performs database work only. Every external webhook is persisted as
 * its own delivery and executed by a separate short-lived job, so one slow
 * customer endpoint cannot hold up the POS outbox or another endpoint.
 */
class ProcessPosOutboxEvent implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_ATTEMPTS = 5;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 600;

    public function __construct(private readonly int $eventId) {}

    public function uniqueId(): string
    {
        return (string) $this->eventId;
    }

    public function handle(WebhookService $webhooks): void
    {
        try {
            DB::transaction(function () use ($webhooks): void {
                $outbox = PosOutboxEvent::query()->whereKey($this->eventId)->lockForUpdate()->first();

                if (! $outbox || $outbox->processed_at !== null || $outbox->failed_at !== null) {
                    return;
                }

                if ((int) $outbox->attempts >= self::MAX_ATTEMPTS) {
                    $outbox->forceFill([
                        'failed_at' => now(),
                        'available_at' => null,
                        'last_error' => $outbox->last_error ?: 'POS outbox retry limit reached.',
                    ])->save();

                    return;
                }

                $payload = $this->dispatchDomainEvent($outbox);
                $webhooks->dispatch(
                    (int) $outbox->company_id,
                    $outbox->event,
                    $payload,
                    "pos-outbox:{$outbox->getKey()}",
                );

                $outbox->forceFill([
                    'attempts' => (int) $outbox->attempts + 1,
                    'processed_at' => now(),
                    'available_at' => null,
                    'last_error' => null,
                ])->save();
            });
        } catch (Throwable $exception) {
            $this->recordFailure($exception);
            Log::warning('[pos] outbox event processing failed', [
                'event_id' => $this->eventId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function dispatchDomainEvent(PosOutboxEvent $outbox): array
    {
        return match ($outbox->event) {
            'pos.sale.completed' => $this->completedSale($outbox),
            'pos.return.completed' => $this->completedReturn($outbox),
            default => throw new RuntimeException("Unsupported POS outbox event [{$outbox->event}]."),
        };
    }

    /** @return array<string, mixed> */
    private function completedSale(PosOutboxEvent $outbox): array
    {
        $sale = PosSale::query()
            ->where('company_id', $outbox->company_id)
            ->with('invoice')
            ->find($outbox->subject_id);

        if (! $sale || ! $sale->invoice || (int) $sale->invoice->company_id !== (int) $outbox->company_id) {
            throw new RuntimeException('The POS sale or its finance invoice no longer exists in this company.');
        }

        if ($sale->invoice->status !== 'paid') {
            throw new RuntimeException('The POS sale invoice is not fully paid.');
        }

        event(new InvoicePaid($sale->invoice));

        return array_merge((array) $outbox->payload, [
            'sale_id' => $sale->getKey(),
            'receipt_number' => $sale->receipt_number,
            'invoice_id' => $sale->invoice->getKey(),
            'invoice_number' => $sale->invoice->number,
            'business_date' => $sale->business_date?->toDateString(),
            'currency' => $sale->currency,
        ]);
    }

    /** @return array<string, mixed> */
    private function completedReturn(PosOutboxEvent $outbox): array
    {
        $return = PosReturn::query()
            ->where('company_id', $outbox->company_id)
            ->with('sale:id,company_id,uuid,receipt_number')
            ->find($outbox->subject_id);

        if (! $return || ! $return->sale || (int) $return->sale->company_id !== (int) $outbox->company_id) {
            throw new RuntimeException('The POS return or its original sale no longer exists in this company.');
        }

        return array_merge((array) $outbox->payload, [
            'return_id' => $return->getKey(),
            'receipt_number' => $return->receipt_number,
            'sale_id' => $return->sale->getKey(),
            'sale_uuid' => $return->sale->uuid,
            'original_receipt_number' => $return->sale->receipt_number,
            'business_date' => $return->business_date?->toDateString(),
        ]);
    }

    private function recordFailure(Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $outbox = PosOutboxEvent::query()
                ->whereKey($this->eventId)
                ->whereNull('processed_at')
                ->whereNull('failed_at')
                ->lockForUpdate()
                ->first();

            if (! $outbox) {
                return;
            }

            $attempt = (int) $outbox->attempts + 1;
            $dead = $attempt >= self::MAX_ATTEMPTS;
            $seconds = match (true) {
                $attempt <= 1 => 60,
                $attempt === 2 => 300,
                $attempt === 3 => 900,
                default => 1800,
            };

            $outbox->forceFill([
                'attempts' => $attempt,
                'failed_at' => $dead ? now() : null,
                'available_at' => $dead ? null : now()->addSeconds($seconds),
                'last_error' => mb_substr($exception->getMessage(), 0, 5000),
            ])->save();
        });
    }
}
