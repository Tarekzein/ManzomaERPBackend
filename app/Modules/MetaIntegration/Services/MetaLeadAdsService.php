<?php

namespace App\Modules\MetaIntegration\Services;

use App\Modules\CRM\Models\CRMContact;
use App\Modules\MetaIntegration\Events\CrmLeadCreated;
use App\Modules\MetaIntegration\Models\MetaLeadFormMapping;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

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

        // Meta re-delivers a lead until it gets a 2xx, so two workers can run
        // this concurrently. The unique (company_id, meta_lead_id) index is the
        // real guard; this check just avoids the wasted Graph call.
        if (CRMContact::where('company_id', $mapping->company_id)->where('meta_lead_id', $leadgenId)->exists()) {
            return null;
        }

        $client = new MetaGraphClient($mapping->connection);
        // campaign/adset/ad ids are what makes revenue attribution possible later.
        $lead = $client->get($leadgenId, [
            'fields' => 'field_data,created_time,campaign_id,adset_id,ad_id,platform,form_id',
        ]);

        $fields = collect($lead['field_data'] ?? [])->mapWithKeys(
            fn (array $field) => [$field['name'] => $field['values'][0] ?? null]
        );

        $data = $this->applyFieldMapping($mapping->field_mapping, $fields);
        $data['type'] = 'lead';
        $data['source'] = $mapping->default_source;
        $data['meta_lead_id'] = $leadgenId;
        $data['owner_id'] = $mapping->default_owner_id;
        $data['company_id'] = $mapping->company_id;
        $data['meta_campaign_id'] = $lead['campaign_id'] ?? null;
        $data['meta_adset_id'] = $lead['adset_id'] ?? null;
        $data['meta_ad_id'] = $lead['ad_id'] ?? null;
        $data['meta_platform'] = $lead['platform'] ?? 'facebook';
        $data['meta_form_id'] = $lead['form_id'] ?? $mapping->form_id;
        $data['meta_last_synced_at'] = now();

        return $this->createOrMergeContact($mapping->company_id, $data);
    }

    /** Imports every historical lead, following Graph's cursor pagination. */
    public function backfill(MetaLeadFormMapping $mapping, int $maxPages = 50): int
    {
        $client = new MetaGraphClient($mapping->connection);
        $imported = 0;
        $after = null;
        $pages = 0;

        do {
            $response = $client->get("{$mapping->form_id}/leads", array_filter([
                'fields' => 'id',
                'limit' => 100,
                'after' => $after,
            ]));

            foreach ($response['data'] ?? [] as $lead) {
                if ($this->ingest($mapping->page_id, $mapping->form_id, $lead['id'])) {
                    $imported++;
                }
            }

            $after = $response['paging']['cursors']['after'] ?? null;
            $hasNext = isset($response['paging']['next']) && $after !== null;
        } while ($hasNext && ++$pages < $maxPages);

        $mapping->update(['last_synced_at' => now()]);

        return $imported;
    }

    private function applyFieldMapping(array $fieldMapping, Collection $fields): array
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
                // First-touch attribution wins: do not overwrite the campaign
                // that originally produced the contact.
                'meta_campaign_id' => $existing->meta_campaign_id ?: ($data['meta_campaign_id'] ?? null),
                'meta_adset_id' => $existing->meta_adset_id ?: ($data['meta_adset_id'] ?? null),
                'meta_ad_id' => $existing->meta_ad_id ?: ($data['meta_ad_id'] ?? null),
                'meta_platform' => $existing->meta_platform ?: ($data['meta_platform'] ?? null),
                'meta_form_id' => $existing->meta_form_id ?: ($data['meta_form_id'] ?? null),
                'meta_last_synced_at' => now(),
                'custom_attributes' => array_merge($existing->custom_attributes ?? [], $data['custom_attributes'] ?? []),
            ]));

            return $existing->fresh();
        }

        $data['status'] ??= 'new';
        $data['currency'] ??= 'EGP';
        $data['name'] ??= $data['email'] ?? $data['phone'] ?? 'Facebook Lead';

        try {
            $contact = CRMContact::create($data);
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery of the same lead won the race.
            return CRMContact::where('company_id', $companyId)
                ->where('meta_lead_id', $data['meta_lead_id'])
                ->firstOrFail();
        }

        event(new CrmLeadCreated($contact));

        return $contact;
    }
}
