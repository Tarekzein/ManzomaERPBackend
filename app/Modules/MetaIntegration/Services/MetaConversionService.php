<?php

namespace App\Modules\MetaIntegration\Services;

use App\Modules\CRM\Models\CRMContact;
use App\Modules\CRM\Models\CRMOpportunity;
use App\Modules\Finance\Models\FinanceContact;
use App\Modules\Finance\Models\Invoice;
use App\Modules\MetaIntegration\Jobs\SendMetaConversionEvent;
use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\MetaIntegration\Models\MetaEventLog;
use App\Modules\MetaIntegration\Models\MetaEventMapping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MetaConversionService
{
    public function __construct(private readonly MetaHashingService $hashing) {}

    public function recordEvent(int $companyId, string $triggerSource, Model $related, array $context = []): ?MetaEventLog
    {
        $this->assertRelatedCompany($companyId, $related);
        $connection = MetaConnection::where('company_id', $companyId)->first();
        if (! $connection || ! $connection->isConnected()) {
            return null;
        }

        $mapping = MetaEventMapping::where('company_id', $companyId)
            ->where('trigger_source', $triggerSource)
            ->where('is_active', true)
            ->first();

        if (! $mapping) {
            return null;
        }

        $contact = $this->resolveContact($related);

        // Finance contacts do not carry Meta consent. In strict-consent mode,
        // only a CRM contact with an affirmative consent flag is eligible.
        if ($connection->require_consent
            && (! $contact instanceof CRMContact || $contact->meta_consent !== true)) {
            return null;
        }

        $deduplicationKey = $this->deduplicationKey($companyId, $triggerSource, $related);

        // A finance/POS outbox may replay after a downstream webhook failure.
        // Reuse the original conversion log so Meta receives the same event_id
        // and can deduplicate it instead of counting the sale twice. The
        // unique key also closes the check/create race between two workers.
        $existing = MetaEventLog::query()
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
                    $existing = MetaEventLog::query()
                        ->where('deduplication_key', $deduplicationKey)
                        ->firstOrFail();
                }
            }

            return $this->reuse($existing);
        }

        $userData = $this->buildUserData($contact, $context);
        $customData = $this->buildCustomData($mapping, $related);

        $payload = array_filter([
            'event_name' => $mapping->meta_event_name,
            'event_time' => now()->timestamp,
            'event_id' => (string) Str::uuid(),
            'action_source' => 'system_generated',
            'user_data' => $userData,
            'custom_data' => $customData,
        ]);

        if ($connection->ldu_enabled) {
            $payload['data_processing_options'] = ['LDU'];
            $payload['data_processing_options_country'] = $connection->ldu_country ?? 0;
            $payload['data_processing_options_state'] = $connection->ldu_state ?? 0;
        }

        if ($connection->test_event_code) {
            $payload['test_event_code'] = $connection->test_event_code;
        }

        $log = MetaEventLog::query()->firstOrCreate(['deduplication_key' => $deduplicationKey], [
            'company_id' => $companyId,
            'meta_connection_id' => $connection->id,
            'event_id' => $payload['event_id'],
            'event_name' => $mapping->meta_event_name,
            'trigger_source' => $triggerSource,
            'related_type' => $related::class,
            'related_id' => $related->getKey(),
            'payload' => $payload,
            'status' => 'pending',
        ]);

        if (! $log->wasRecentlyCreated) {
            return $this->reuse($log);
        }

        SendMetaConversionEvent::dispatch($log->id)->onQueue('meta-events')->afterCommit();

        return $log;
    }

    public function retry(MetaEventLog $log): void
    {
        $log->update(['status' => 'pending', 'next_retry_at' => null]);
        SendMetaConversionEvent::dispatch($log->id)->onQueue('meta-events')->afterCommit();
    }

    private function reuse(MetaEventLog $log): MetaEventLog
    {
        if ($log->status !== 'sent' && $log->isRetryEligible()) {
            SendMetaConversionEvent::dispatch($log->id)->onQueue('meta-events')->afterCommit();
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

    private function buildUserData(CRMContact|FinanceContact|null $contact, array $context): array
    {
        [$firstName, $lastName] = $this->splitName($contact?->name);
        $crmContact = $contact instanceof CRMContact ? $contact : null;

        return $this->hashing->hashedUserData(
            email: $contact?->email,
            phone: $contact?->phone,
            firstName: $firstName,
            lastName: $lastName,
            fbc: $crmContact?->meta_fbc,
            fbp: $crmContact?->meta_fbp,
            clientIp: $context['client_ip_address'] ?? null,
            userAgent: $context['client_user_agent'] ?? null,
            externalId: $contact ? (string) $contact->id : null,
        );
    }

    private function buildCustomData(MetaEventMapping $mapping, Model $related): array
    {
        $data = $mapping->extra_params ?? [];

        if ($mapping->value_field) {
            $value = data_get($related, $this->relativeField($mapping->value_field));
            if ($value !== null) {
                $data['value'] = (float) $value;
            }
        }

        if ($mapping->currency_field) {
            $currency = data_get($related, $this->relativeField($mapping->currency_field));
            if ($currency !== null) {
                $data['currency'] = $currency;
            }
        } elseif (isset($data['value']) && $related->currency ?? null) {
            $data['currency'] = $related->currency;
        }

        return $data;
    }

    private function relativeField(string $field): string
    {
        // Mapping fields are stored as "opportunity.value" / "invoice.total"; the related
        // model itself is the root, so strip a leading "<model>." segment if present.
        $segments = explode('.', $field, 2);

        return count($segments) === 2 ? $segments[1] : $field;
    }

    private function splitName(?string $name): array
    {
        if (! $name) {
            return [null, null];
        }

        $parts = preg_split('/\s+/', trim($name), 2);

        return [$parts[0] ?? null, $parts[1] ?? null];
    }
}
