<?php

namespace App\Modules\TikTokIntegration\Services;

use App\Modules\CRM\Models\CRMContact;
use App\Modules\MetaIntegration\Events\CrmLeadCreated;
use App\Modules\TikTokIntegration\Exceptions\TikTokApiException;
use App\Modules\TikTokIntegration\Models\TikTokConnection;
use App\Modules\TikTokIntegration\Models\TikTokLeadFormMapping;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lead Ads ingestion.
 *
 * TikTok does not push leads over a webhook the way Meta does. Instead you ask
 * for an export, TikTok builds it asynchronously, and you poll until a download
 * is ready. That makes ingestion a two-phase job: request, then collect — which
 * is why a mapping carries the current task id and how far it has synced.
 */
class TikTokLeadService
{
    public function __construct(private readonly TikTokConnectionNotifier $notifier) {}

    /** Instant pages (lead forms) available to an advertiser. */
    public function forms(TikTokConnection $connection, string $advertiserId): array
    {
        return (new TikTokClient($connection))->get('page/get', [
            'advertiser_id' => $advertiserId,
            'page_type' => 'LEAD_GEN',
            'page_size' => 100,
        ])['list'] ?? [];
    }

    /**
     * Advance one mapping: request an export when none is running, otherwise
     * collect the one that is.
     *
     * @return string the outcome, for the command's summary
     */
    public function sync(TikTokLeadFormMapping $mapping): string
    {
        if ($mapping->taskHasStalled()) {
            // TikTok never finished it; drop it and ask again.
            Log::warning('[tiktok] lead export task stalled, restarting', [
                'mapping_id' => $mapping->id,
                'task_id' => $mapping->current_task_id,
            ]);
            $mapping->forceFill(['current_task_id' => null, 'task_status' => null])->save();
        }

        try {
            return $mapping->hasPendingTask()
                ? $this->collect($mapping)
                : $this->request($mapping);
        } catch (TikTokApiException $exception) {
            $mapping->forceFill(['last_error' => $exception->getMessage()])->save();
            $this->notifier->syncFailed($mapping->connection, 'lead', $exception->getMessage());

            return 'failed';
        }
    }

    /** Ask TikTok to build an export covering everything since the last run. */
    private function request(TikTokLeadFormMapping $mapping): string
    {
        // First run reaches back a week; after that, from where we stopped.
        $since = $mapping->synced_through ?? now()->subWeek();
        $until = now();

        $response = (new TikTokClient($mapping->connection))->post('page/lead/task/create', [
            'advertiser_id' => $mapping->advertiser_id,
            'page_id' => $mapping->page_id,
            'start_time' => $since->timestamp,
            'end_time' => $until->timestamp,
        ]);

        $taskId = $response['task_id'] ?? null;

        if (! $taskId) {
            $mapping->forceFill(['last_error' => 'TikTok did not return an export task id.'])->save();

            return 'failed';
        }

        $mapping->forceFill([
            'current_task_id' => (string) $taskId,
            'task_status' => 'PROCESSING',
            'task_requested_at' => now(),
            'last_error' => null,
            // Remembered so the next request starts where this one ends.
            'synced_through' => $until,
        ])->save();

        return 'requested';
    }

    /** Collect a finished export and turn its rows into CRM contacts. */
    private function collect(TikTokLeadFormMapping $mapping): string
    {
        $response = (new TikTokClient($mapping->connection))->get('page/lead/task/get', [
            'advertiser_id' => $mapping->advertiser_id,
            'task_id' => $mapping->current_task_id,
        ]);

        $status = strtoupper((string) ($response['status'] ?? 'PROCESSING'));

        if ($status === 'PROCESSING' || $status === 'QUEUING') {
            return 'pending';
        }

        if ($status !== 'SUCCESS') {
            $mapping->forceFill([
                'current_task_id' => null,
                'task_status' => $status,
                'last_error' => "TikTok reported export status {$status}.",
            ])->save();

            return 'failed';
        }

        $rows = $this->rowsFrom($response);
        $imported = 0;

        foreach ($rows as $row) {
            if ($this->ingest($mapping, $row)) {
                $imported++;
            }
        }

        $mapping->forceFill([
            'current_task_id' => null,
            'task_status' => 'SUCCESS',
            'last_synced_at' => now(),
            'last_error' => null,
        ])->save();

        Log::info('[tiktok] leads imported', [
            'mapping_id' => $mapping->id,
            'company_id' => $mapping->company_id,
            'imported' => $imported,
            'rows' => count($rows),
        ]);

        return $imported > 0 ? 'imported' : 'empty';
    }

    /**
     * The export arrives either inline or behind a signed download URL.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rowsFrom(array $response): array
    {
        if (! empty($response['list']) && is_array($response['list'])) {
            return $response['list'];
        }

        $url = $response['download_url'] ?? null;

        if (! $url) {
            return [];
        }

        $body = Http::timeout(60)->get($url)->body();
        $decoded = json_decode($body, true);

        // TikTok serves JSON for small exports and CSV for large ones.
        return is_array($decoded)
            ? ($decoded['list'] ?? $decoded)
            : $this->parseCsv($body);
    }

    /** @return array<int, array<string, mixed>> */
    private function parseCsv(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($body)) ?: [];

        if (count($lines) < 2) {
            return [];
        }

        $headers = str_getcsv(array_shift($lines));
        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line);
            if (count($values) === count($headers)) {
                $rows[] = array_combine($headers, $values);
            }
        }

        return $rows;
    }

    /** Create or merge the CRM contact for one exported lead. */
    public function ingest(TikTokLeadFormMapping $mapping, array $row): ?CRMContact
    {
        $leadId = (string) ($row['lead_id'] ?? $row['id'] ?? '');

        if ($leadId === '') {
            return null;
        }

        if (CRMContact::where('company_id', $mapping->company_id)->where('tiktok_lead_id', $leadId)->exists()) {
            return null;
        }

        $answers = $this->answers($row);
        $data = $this->applyFieldMapping($mapping->field_mapping, $answers);

        $data['company_id'] = $mapping->company_id;
        $data['type'] = 'lead';
        $data['source'] = $mapping->default_source ?: 'tiktok';
        $data['owner_id'] = $mapping->default_owner_id;
        $data['tiktok_lead_id'] = $leadId;
        $data['tiktok_campaign_id'] = $row['campaign_id'] ?? null;
        $data['tiktok_adgroup_id'] = $row['adgroup_id'] ?? null;
        $data['tiktok_ad_id'] = $row['ad_id'] ?? null;
        $data['status'] ??= 'new';
        $data['currency'] ??= 'EGP';
        $data['name'] ??= $data['email'] ?? $data['phone'] ?? 'TikTok Lead';

        return $this->createOrMerge($mapping, $data);
    }

    /**
     * Field answers arrive either as a flat row (CSV) or as a list of
     * question/answer pairs (JSON).
     *
     * @return Collection<string, mixed>
     */
    private function answers(array $row): Collection
    {
        if (! empty($row['field_data']) && is_array($row['field_data'])) {
            return collect($row['field_data'])->mapWithKeys(fn (array $field) => [
                (string) ($field['name'] ?? $field['question'] ?? '') => $field['values'][0] ?? ($field['answer'] ?? null),
            ]);
        }

        return collect($row)->except(['lead_id', 'id', 'campaign_id', 'adgroup_id', 'ad_id', 'create_time']);
    }

    /** @param  Collection<string, mixed>  $answers */
    private function applyFieldMapping(array $fieldMapping, Collection $answers): array
    {
        $data = [];

        foreach ($fieldMapping as $tiktokField => $crmField) {
            $value = $answers->get($tiktokField);

            if ($value === null || $value === '') {
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

    private function createOrMerge(TikTokLeadFormMapping $mapping, array $data): CRMContact
    {
        $existing = CRMContact::where('company_id', $mapping->company_id)
            ->where(function ($query) use ($data) {
                if (! empty($data['email'])) {
                    $query->orWhere('email', $data['email']);
                }
                if (! empty($data['phone'])) {
                    $query->orWhere('phone', $data['phone']);
                }
            })
            ->when(empty($data['email']) && empty($data['phone']), fn ($query) => $query->whereRaw('1 = 0'))
            ->first();

        if ($existing) {
            $existing->update(array_filter([
                'source' => $existing->source ?: $data['source'],
                'tiktok_lead_id' => $data['tiktok_lead_id'],
                // First-touch attribution: keep whatever won the contact first.
                'tiktok_campaign_id' => $existing->tiktok_campaign_id ?: ($data['tiktok_campaign_id'] ?? null),
                'tiktok_adgroup_id' => $existing->tiktok_adgroup_id ?: ($data['tiktok_adgroup_id'] ?? null),
                'tiktok_ad_id' => $existing->tiktok_ad_id ?: ($data['tiktok_ad_id'] ?? null),
                'custom_attributes' => array_merge($existing->custom_attributes ?? [], $data['custom_attributes'] ?? []),
            ]));

            return $existing->fresh();
        }

        try {
            $contact = CRMContact::create($data);
        } catch (UniqueConstraintViolationException) {
            // A concurrent import of the same export won the race.
            return CRMContact::where('company_id', $mapping->company_id)
                ->where('tiktok_lead_id', $data['tiktok_lead_id'])
                ->firstOrFail();
        }

        // Shared with Meta: feeds conversion events and any CRM automation.
        event(new CrmLeadCreated($contact));

        return $contact;
    }
}
