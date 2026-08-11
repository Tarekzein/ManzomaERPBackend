<?php

namespace App\Modules\TikTokIntegration\Services;

use App\Modules\MetaIntegration\Services\MetaHashingService;
use App\Modules\TikTokIntegration\Jobs\SendTikTokEvent;
use App\Modules\TikTokIntegration\Models\TikTokConnection;
use App\Modules\TikTokIntegration\Models\TikTokEventLog;
use App\Modules\TikTokIntegration\Models\TikTokEventMapping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Server-side conversions through the TikTok Events API 2.0.
 *
 * Mirrors the Meta Conversions flow: a CRM or finance trigger is mapped to a
 * TikTok event, recorded in a log, and delivered by a queued job so a slow or
 * failing API never blocks the business transaction that caused it.
 */
class TikTokEventService
{
    public function __construct(private readonly MetaHashingService $hashing) {}

    /** Queue an event for delivery if the company mapped this trigger. */
    public function recordEvent(int $companyId, string $triggerSource, Model $related): ?TikTokEventLog
    {
        $connection = TikTokConnection::where('company_id', $companyId)->first();

        if (! $connection?->isConnected() || ! $connection->events_enabled || ! $connection->pixel_code) {
            return null;
        }

        $mapping = TikTokEventMapping::where('company_id', $companyId)
            ->where('trigger_source', $triggerSource)
            ->where('is_active', true)
            ->first();

        if (! $mapping) {
            return null;
        }

        $log = TikTokEventLog::create([
            'company_id' => $companyId,
            'tiktok_connection_id' => $connection->id,
            // Deduplicates against the pixel's browser-side event.
            'event_id' => (string) Str::uuid(),
            'event_name' => $mapping->event_name,
            'trigger_source' => $triggerSource,
            'related_type' => $related::class,
            'related_id' => $related->getKey(),
            'status' => 'pending',
            'payload' => $this->buildPayload($connection, $mapping, $related),
        ]);

        SendTikTokEvent::dispatch($log->id)->onQueue('tiktok-events');

        return $log;
    }

    /** Deliver a queued event. Identifiers are SHA-256 hashed, as TikTok requires. */
    public function send(TikTokEventLog $log): void
    {
        $connection = $log->connection;

        $response = (new TikTokClient($connection))->post('event/track', [
            'event_source' => 'web',
            'event_source_id' => $connection->pixel_code,
            'data' => [$log->payload],
        ]);

        $log->forceFill([
            'status' => 'sent',
            'sent_at' => now(),
            'response' => $response,
            'error' => null,
        ])->save();
    }

    /** @return array<string, mixed> */
    private function buildPayload(TikTokConnection $connection, TikTokEventMapping $mapping, Model $related): array
    {
        $value = $mapping->value_field ? (float) data_get($related, $mapping->value_field, 0) : null;
        $currency = $mapping->currency_field
            ? (string) data_get($related, $mapping->currency_field, 'EGP')
            : null;

        return array_filter([
            'event' => $mapping->event_name,
            'event_time' => now()->timestamp,
            'event_id' => (string) Str::uuid(),
            'user' => array_filter([
                // TikTok wants the same normalisation as Meta (lowercase-trim,
                // digits-only phone) before SHA-256, so the service is shared.
                'email' => $this->hashing->hash($this->hashing->normalizeEmail(data_get($related, 'email'))),
                'phone' => $this->hashing->hash($this->hashing->normalizePhone(data_get($related, 'phone'))),
                'external_id' => $this->hashing->hash((string) $related->getKey()),
            ]),
            'properties' => array_filter([
                'value' => $value,
                'currency' => $currency,
                'content_type' => 'product',
            ], fn ($item) => $item !== null),
        ] + ($mapping->extra_params ?? []), fn ($item) => $item !== null && $item !== []);
    }
}
