<?php

namespace App\Modules\TikTokIntegration\Services;

use App\Modules\CRM\Models\CRMContact;
use App\Modules\CRM\Models\CRMOpportunity;
use App\Modules\Finance\Models\FinanceContact;
use App\Modules\Finance\Models\Invoice;
use App\Modules\MetaIntegration\Services\MetaHashingService;
use App\Modules\TikTokIntegration\Jobs\SendTikTokEvent;
use App\Modules\TikTokIntegration\Models\TikTokConnection;
use App\Modules\TikTokIntegration\Models\TikTokEventLog;
use App\Modules\TikTokIntegration\Models\TikTokEventMapping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use InvalidArgumentException;

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
        $this->assertRelatedCompany($companyId, $related);
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

        $deduplicationKey = $this->deduplicationKey($companyId, $triggerSource, $related);

        // Transactional outbox retries must retain one TikTok event identity.
        // Returning the existing log also avoids duplicate conversion counts.
        // The unique key closes the check/create race between queue workers.
        $existing = TikTokEventLog::query()
            ->where('company_id', $companyId)
            ->where('trigger_source', $triggerSource)
            ->where('related_type', $related::class)
            ->where('related_id', $related->getKey())
            ->latest('id')
            ->first();

        if ($existing) {
            if ($existing->deduplication_key === null) {
                try {
                    $existing->forceFill(['deduplication_key' => $deduplicationKey])->save();
                } catch (UniqueConstraintViolationException) {
                    $existing = TikTokEventLog::query()
                        ->where('deduplication_key', $deduplicationKey)
                        ->firstOrFail();
                }
            }

            return $this->reuse($existing);
        }

        $log = TikTokEventLog::query()->firstOrCreate(['deduplication_key' => $deduplicationKey], [
            'company_id' => $companyId,
            'tiktok_connection_id' => $connection->id,
            // Deduplicates against the pixel's browser-side event.
            'event_id' => (string) Str::uuid(),
            'event_name' => $mapping->event_name,
            'trigger_source' => $triggerSource,
            'related_type' => $related::class,
            'related_id' => $related->getKey(),
            'status' => 'pending',
            'payload' => $this->buildPayload($mapping, $related),
        ]);

        if (! $log->wasRecentlyCreated) {
            return $this->reuse($log);
        }

        SendTikTokEvent::dispatch($log->id)->onQueue('tiktok-events')->afterCommit();

        return $log;
    }

    private function reuse(TikTokEventLog $log): TikTokEventLog
    {
        if ($log->status === 'pending'
            && ($log->next_retry_at === null || $log->next_retry_at->isPast())) {
            SendTikTokEvent::dispatch($log->id)->onQueue('tiktok-events')->afterCommit();
        }

        return $log;
    }

    private function deduplicationKey(int $companyId, string $triggerSource, Model $related): string
    {
        return hash('sha256', implode('|', [
            (string) $companyId,
            $triggerSource,
            $related::class,
            (string) $related->getKey(),
        ]));
    }

    private function assertRelatedCompany(int $companyId, Model $related): void
    {
        if ((int) $related->getAttribute('company_id') !== $companyId) {
            throw new InvalidArgumentException('A conversion event cannot cross company boundaries.');
        }
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
    private function buildPayload(TikTokEventMapping $mapping, Model $related): array
    {
        $contact = $this->resolveContact($related);
        $value = $mapping->value_field
            ? (float) data_get($related, $this->relativeField($mapping->value_field), 0)
            : null;
        $currency = $mapping->currency_field
            ? (string) data_get($related, $this->relativeField($mapping->currency_field), 'EGP')
            : ($value !== null ? (string) ($related->getAttribute('currency') ?: 'EGP') : null);

        return array_filter([
            'event' => $mapping->event_name,
            'event_time' => now()->timestamp,
            'event_id' => (string) Str::uuid(),
            'user' => array_filter([
                // TikTok wants the same normalisation as Meta (lowercase-trim,
                // digits-only phone) before SHA-256, so the service is shared.
                'email' => $this->hashing->hash($this->hashing->normalizeEmail($contact?->email)),
                'phone' => $this->hashing->hash($this->hashing->normalizePhone($contact?->phone)),
                'external_id' => $this->hashing->hash((string) ($contact?->getKey() ?? $related->getKey())),
            ]),
            'properties' => array_filter([
                'value' => $value,
                'currency' => $currency,
                'content_type' => 'product',
            ], fn ($item) => $item !== null),
        ] + ($mapping->extra_params ?? []), fn ($item) => $item !== null && $item !== []);
    }

    private function resolveContact(Model $related): CRMContact|FinanceContact|null
    {
        $contact = match (true) {
            $related instanceof CRMContact => $related,
            $related instanceof CRMOpportunity => $related->contact,
            $related instanceof Invoice => $related->contact,
            default => null,
        };

        if ($contact && (int) $contact->company_id !== (int) $related->getAttribute('company_id')) {
            throw new InvalidArgumentException('A conversion contact cannot cross company boundaries.');
        }

        return $contact;
    }

    private function relativeField(string $field): string
    {
        $segments = explode('.', $field, 2);

        return count($segments) === 2 ? $segments[1] : $field;
    }
}
