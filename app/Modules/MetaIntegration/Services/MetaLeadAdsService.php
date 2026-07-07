<?php

namespace App\Modules\MetaIntegration\Services;

use App\Modules\CRM\Models\CRMContact;
use App\Modules\MetaIntegration\Events\CrmLeadCreated;
use App\Modules\MetaIntegration\Models\MetaLeadFormMapping;

class MetaLeadAdsService
{
    public function ingest(string $pageId, string $formId, string $leadgenId): ?CRMContact
    {
        $mapping = MetaLeadFormMapping::with('connection')
            ->where('page_id', $pageId)
            ->where('form_id', $formId)
            ->where('is_active', true)
            ->first();

        if (! $mapping || ! $mapping->connection) {
            return null;
        }

        if (CRMContact::where('meta_lead_id', $leadgenId)->exists()) {
            return null;
        }

        $client = new MetaGraphClient($mapping->connection);
        $lead = $client->get($leadgenId, ['fields' => 'field_data']);

        $fields = collect($lead['field_data'] ?? [])->mapWithKeys(
            fn (array $field) => [$field['name'] => $field['values'][0] ?? null]
        );

        $data = $this->applyFieldMapping($mapping->field_mapping, $fields);
        $data['type'] = 'lead';
        $data['source'] = $mapping->default_source;
        $data['meta_lead_id'] = $leadgenId;
        $data['owner_id'] = $mapping->default_owner_id;
        $data['company_id'] = $mapping->company_id;

        return $this->createOrMergeContact($mapping->company_id, $data);
    }

    public function backfill(MetaLeadFormMapping $mapping): int
    {
        $client = new MetaGraphClient($mapping->connection);
        $leads = $client->get("{$mapping->form_id}/leads", ['fields' => 'id'])['data'] ?? [];

        $imported = 0;
        foreach ($leads as $lead) {
            if ($this->ingest($mapping->page_id, $mapping->form_id, $lead['id'])) {
                $imported++;
            }
        }

        $mapping->update(['last_synced_at' => now()]);

        return $imported;
    }

    private function applyFieldMapping(array $fieldMapping, \Illuminate\Support\Collection $fields): array
    {
        $data = [];
        foreach ($fieldMapping as $metaField => $crmField) {
            $value = $fields->get($metaField);
            if ($value === null) {
                continue;
            }
            if (in_array($crmField, ['name', 'email', 'phone', 'company_name', 'region'], true)) {
                $data[$crmField] = $value;
            } else {
                $data['custom_attributes'][$crmField] = $value;
            }
        }

        return $data;
    }

    private function createOrMergeContact(int $companyId, array $data): CRMContact
    {
        $existing = CRMContact::where('company_id', $companyId)
            ->where(function ($query) use ($data) {
                if (! empty($data['email'])) {
                    $query->orWhere('email', $data['email']);
                }
                if (! empty($data['phone'])) {
                    $query->orWhere('phone', $data['phone']);
                }
            })
            ->first();

        if ($existing) {
            $existing->update(array_filter([
                'source' => $existing->source ?: $data['source'],
                'meta_lead_id' => $data['meta_lead_id'],
                'custom_attributes' => array_merge($existing->custom_attributes ?? [], $data['custom_attributes'] ?? []),
            ]));

            return $existing->fresh();
        }

        $data['status'] ??= 'new';
        $data['currency'] ??= 'EGP';
        $data['name'] ??= $data['email'] ?? $data['phone'] ?? 'Facebook Lead';
        $contact = CRMContact::create($data);
        event(new CrmLeadCreated($contact));

        return $contact;
    }
}
