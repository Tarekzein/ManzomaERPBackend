<?php

namespace App\Modules\MetaIntegration\Services;

use App\Modules\CRM\Models\CRMContact;
use App\Modules\MetaIntegration\Jobs\SyncMetaAudienceJob;
use App\Modules\MetaIntegration\Models\MetaAudienceSync;

class MetaAudienceService
{
    public function __construct(private readonly MetaHashingService $hashing) {}

    public function createAudience(MetaAudienceSync $sync): MetaAudienceSync
    {
        $client = new MetaGraphClient($sync->connection);
        $response = $client->post("{$sync->connection->business_id}/customaudiences", [
            'name' => $sync->audience_name,
            'subtype' => 'CUSTOM',
            'customer_file_source' => 'USER_PROVIDED_ONLY',
        ]);

        $sync->update(['meta_audience_id' => $response['id'] ?? null]);

        return $sync->fresh();
    }

    public function sync(MetaAudienceSync $sync): void
    {
        SyncMetaAudienceJob::dispatch($sync->id)->onQueue('meta-events');
    }

    public function segmentContactsQuery(int $companyId, array $criteria)
    {
        return CRMContact::query()
            ->where('company_id', $companyId)
            ->when(! empty($criteria['types']), fn ($q) => $q->whereIn('type', $criteria['types']))
            ->when(! empty($criteria['regions']), fn ($q) => $q->whereIn('region', $criteria['regions']))
            ->when(! empty($criteria['owner_id']), fn ($q) => $q->where('owner_id', $criteria['owner_id']))
            ->when(! empty($criteria['tag_ids']), fn ($q) => $q->whereHas(
                'tags',
                fn ($tagQuery) => $tagQuery->whereIn('crm_tags.id', $criteria['tag_ids'])
            ));
    }

    public function hashedIdentifiers(CRMContact $contact): array
    {
        return array_filter([
            $this->hashing->hash($this->hashing->normalizeEmail($contact->email)),
            $this->hashing->hash($this->hashing->normalizePhone($contact->phone)),
        ]);
    }

    public function removeContact(CRMContact $contact): void
    {
        $syncs = MetaAudienceSync::with('connection', 'segment')
            ->whereHas('segment', fn ($q) => $q->where('company_id', $contact->company_id))
            ->whereNotNull('meta_audience_id')
            ->get();

        foreach ($syncs as $sync) {
            $identifiers = $this->hashedIdentifiers($contact);
            if (! $identifiers || ! $sync->connection) {
                continue;
            }

            (new MetaGraphClient($sync->connection))->delete("{$sync->meta_audience_id}/users", [
                'payload' => json_encode([
                    'schema' => ['EMAIL', 'PHONE'],
                    'data' => [$identifiers],
                ]),
            ]);
        }
    }
}
