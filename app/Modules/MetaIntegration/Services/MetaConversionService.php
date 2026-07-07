<?php

namespace App\Modules\MetaIntegration\Services;

use App\Modules\CRM\Models\CRMContact;
use App\Modules\CRM\Models\CRMOpportunity;
use App\Modules\Finance\Models\Invoice;
use App\Modules\MetaIntegration\Jobs\SendMetaConversionEvent;
use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\MetaIntegration\Models\MetaEventLog;
use App\Modules\MetaIntegration\Models\MetaEventMapping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MetaConversionService
{
    public function __construct(private readonly MetaHashingService $hashing) {}

    public function recordEvent(int $companyId, string $triggerSource, Model $related, array $context = []): ?MetaEventLog
    {
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

        if ($connection->require_consent && $contact instanceof CRMContact && $contact->meta_consent !== true) {
            return null;
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

        $log = MetaEventLog::create([
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

        SendMetaConversionEvent::dispatch($log->id)->onQueue('meta-events');

        return $log;
    }

    public function retry(MetaEventLog $log): void
    {
        $log->update(['status' => 'pending', 'next_retry_at' => null]);
        SendMetaConversionEvent::dispatch($log->id)->onQueue('meta-events');
    }

    private function resolveContact(Model $related): ?CRMContact
    {
        return match (true) {
            $related instanceof CRMContact => $related,
            $related instanceof CRMOpportunity => $related->contact,
            default => null,
        };
    }

    private function buildUserData(?CRMContact $contact, array $context): array
    {
        [$firstName, $lastName] = $this->splitName($contact?->name);

        return $this->hashing->hashedUserData(
            email: $contact?->email,
            phone: $contact?->phone,
            firstName: $firstName,
            lastName: $lastName,
            fbc: $contact?->meta_fbc,
            fbp: $contact?->meta_fbp,
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
