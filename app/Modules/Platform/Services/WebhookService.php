<?php

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Jobs\DeliverWebhook;
use App\Modules\Platform\Models\WebhookDelivery;
use App\Modules\Platform\Models\WebhookEndpoint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Throwable;

class WebhookService
{
    private const MAX_ATTEMPTS = 5;

    /**
     * Persist one delivery per subscribed endpoint and queue each separately.
     *
     * No network request happens here. The caller can safely invoke this from
     * a business transaction; jobs are released only after that transaction
     * commits, and the retry command recovers any enqueue failure.
     *
     * @return Collection<int, WebhookDelivery>
     */
    public function dispatch(int $companyId, string $event, array $payload, ?string $sourceKey = null): Collection
    {
        return WebhookEndpoint::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint) => in_array('*', $endpoint->events, true)
                || in_array($event, $endpoint->events, true))
            ->map(function (WebhookEndpoint $endpoint) use ($event, $payload, $sourceKey): WebhookDelivery {
                $deliveryId = $sourceKey === null
                    ? (string) Str::uuid()
                    : $this->deterministicDeliveryId($sourceKey, $endpoint->getKey());
                $delivery = $this->prepare($endpoint, $event, $payload, $deliveryId);

                DeliverWebhook::dispatch($delivery->getKey())->afterCommit();

                return $delivery;
            })
            ->values();
    }

    /** Queue an administrator-requested retry without changing its delivery ID. */
    public function retry(WebhookDelivery $delivery): WebhookDelivery
    {
        DB::transaction(function () use ($delivery): void {
            $locked = WebhookDelivery::query()->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();
            $endpoint = WebhookEndpoint::query()->whereKey($locked->webhook_endpoint_id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'delivered') {
                $endpoint->forceFill([
                    'is_active' => true,
                    'failure_count' => 0,
                    'disabled_at' => null,
                ])->save();
                $locked->forceFill([
                    'status' => 'queued',
                    'attempts' => 0,
                    'next_attempt_at' => now()->addMinutes(5),
                    'claim_token' => null,
                ])->save();
            }
        });

        if ($delivery->fresh()?->status !== 'delivered') {
            DeliverWebhook::dispatch($delivery->getKey())->afterCommit();
        }

        return $delivery->fresh();
    }

    /** Perform one already-persisted delivery. Called only by the queue job. */
    public function deliver(int $deliveryId): ?WebhookDelivery
    {
        $delivery = $this->claim($deliveryId);
        if (! $delivery) {
            return WebhookDelivery::query()->find($deliveryId);
        }

        $endpoint = $delivery->endpoint;
        $body = (array) $delivery->payload;

        try {
            $json = json_encode($body, JSON_THROW_ON_ERROR);
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-Manzoma-Delivery' => $delivery->delivery_id,
                    'X-Manzoma-Event' => $delivery->event,
                    'X-Manzoma-Signature' => hash_hmac('sha256', $json, $endpoint->secret),
                ])
                ->withBody($json, 'application/json')
                ->post($endpoint->url);

            return $this->finish(
                $delivery,
                $response->successful(),
                $response->status(),
                mb_substr($response->body(), 0, 5000),
            );
        } catch (Throwable $exception) {
            return $this->finish($delivery, false, null, mb_substr($exception->getMessage(), 0, 5000));
        }
    }

    private function prepare(
        WebhookEndpoint $endpoint,
        string $event,
        array $payload,
        string $deliveryId,
    ): WebhookDelivery {
        $body = [
            'id' => $deliveryId,
            'event' => $event,
            'created_at' => now()->toIso8601String(),
            'data' => $payload,
        ];

        return $endpoint->deliveries()->firstOrCreate(['delivery_id' => $deliveryId], [
            'event' => $event,
            'payload' => $body,
            'attempts' => 0,
            'status' => 'pending',
        ]);
    }

    private function claim(int $deliveryId): ?WebhookDelivery
    {
        return DB::transaction(function () use ($deliveryId): ?WebhookDelivery {
            $delivery = WebhookDelivery::query()->whereKey($deliveryId)->lockForUpdate()->first();

            if (! $delivery || $delivery->status === 'delivered' || (int) $delivery->attempts >= self::MAX_ATTEMPTS) {
                return null;
            }

            $endpoint = WebhookEndpoint::query()->whereKey($delivery->webhook_endpoint_id)->lockForUpdate()->first();
            if (! $endpoint || ! $endpoint->is_active) {
                $delivery->forceFill([
                    'status' => 'failed',
                    'next_attempt_at' => null,
                    'response_body' => 'Webhook endpoint is disabled.',
                    'claim_token' => null,
                ])->save();

                return null;
            }

            // A scheduler lease uses status=queued with a future timestamp. A
            // processing lease uses status=processing and must be respected.
            if ($delivery->status !== 'queued' && $delivery->next_attempt_at?->isFuture()) {
                return null;
            }

            $delivery->forceFill([
                'status' => 'processing',
                'attempts' => (int) $delivery->attempts + 1,
                'claim_token' => (string) Str::uuid(),
                'next_attempt_at' => now()->addSeconds(30),
                'response_status' => null,
                'response_body' => null,
            ])->save();

            return $delivery->refresh()->load('endpoint');
        });
    }

    private function finish(
        WebhookDelivery $claim,
        bool $success,
        ?int $responseStatus,
        ?string $responseBody,
    ): WebhookDelivery {
        DB::transaction(function () use ($claim, $success, $responseStatus, $responseBody): void {
            $delivery = WebhookDelivery::query()
                ->whereKey($claim->getKey())
                ->where('claim_token', $claim->claim_token)
                ->lockForUpdate()
                ->first();

            if (! $delivery) {
                return;
            }

            $endpoint = WebhookEndpoint::query()->whereKey($delivery->webhook_endpoint_id)->lockForUpdate()->firstOrFail();
            $failures = $success ? 0 : (int) $endpoint->failure_count + 1;
            $retryable = ! $success
                && (int) $delivery->attempts < self::MAX_ATTEMPTS
                && $failures < self::MAX_ATTEMPTS;

            $delivery->forceFill([
                'status' => $success ? 'delivered' : 'failed',
                'response_status' => $responseStatus,
                'response_body' => $responseBody,
                'delivered_at' => $success ? now() : null,
                'next_attempt_at' => $retryable ? now()->addMinutes(5) : null,
                'claim_token' => null,
            ])->save();

            $endpoint->forceFill([
                'failure_count' => $failures,
                'is_active' => $success || $failures < self::MAX_ATTEMPTS,
                'last_delivered_at' => $success ? now() : $endpoint->last_delivered_at,
                'disabled_at' => $success || $failures < self::MAX_ATTEMPTS ? null : now(),
            ])->save();
        });

        return $claim->fresh();
    }

    private function deterministicDeliveryId(string $sourceKey, int $endpointId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "manzoma:{$sourceKey}:endpoint:{$endpointId}")->toString();
    }
}
