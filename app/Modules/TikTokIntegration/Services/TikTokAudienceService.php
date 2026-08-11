<?php

namespace App\Modules\TikTokIntegration\Services;

use App\Modules\CRM\Models\CRMContact;
use App\Modules\MetaIntegration\Services\MetaAudienceService;
use App\Modules\MetaIntegration\Services\MetaHashingService;
use App\Modules\TikTokIntegration\Exceptions\TikTokApiException;
use App\Modules\TikTokIntegration\Jobs\SyncTikTokAudienceJob;
use App\Modules\TikTokIntegration\Models\TikTokAudienceSync;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Custom Audiences from CRM segments.
 *
 * TikTok's DMP API differs from Meta's in shape: rather than posting a JSON
 * batch of hashed identifiers, you upload a newline-delimited file, receive a
 * `file_path`, and then create or update the audience from it. One file carries
 * one identifier type, so email and phone audiences are separate uploads.
 */
class TikTokAudienceService
{
    public function __construct(
        private readonly MetaHashingService $hashing,
        private readonly MetaAudienceService $segments,
        private readonly TikTokConnectionNotifier $notifier,
    ) {}

    public function queueSync(TikTokAudienceSync $sync): void
    {
        if (in_array($sync->status, ['queued', 'processing'], true)) {
            return;
        }

        $sync->forceFill(['status' => 'queued', 'last_error' => null])->save();
        SyncTikTokAudienceJob::dispatch($sync->id)->onQueue('tiktok-events');
    }

    /**
     * Push the segment's contacts to TikTok, creating the audience on first run
     * and appending to it afterwards.
     */
    public function sync(TikTokAudienceSync $sync): void
    {
        $sync->loadMissing('connection', 'segment');

        if (! $sync->connection || (int) $sync->connection->company_id !== (int) $sync->company_id) {
            throw new RuntimeException('The audience connection is missing or belongs to another company.');
        }

        if (! $sync->segment || (int) $sync->segment->company_id !== (int) $sync->company_id) {
            throw new RuntimeException('The audience CRM segment is missing or belongs to another company.');
        }

        if (! $sync->connection->isConnected()) {
            throw new RuntimeException('Reconnect TikTok before syncing this audience.');
        }

        $sync->forceFill(['status' => 'processing', 'last_error' => null])->save();

        try {
            $identifiers = $this->identifiers($sync);

            if ($identifiers === []) {
                $sync->forceFill([
                    'status' => 'empty',
                    'approximate_count' => 0,
                    'last_synced_at' => now(),
                    'last_error' => null,
                ])->save();

                return;
            }

            $filePath = $this->uploadIdentifiers($sync, $identifiers);

            $sync->tiktok_audience_id
                ? $this->appendToAudience($sync, $filePath)
                : $this->createAudience($sync, $filePath);

            $sync->forceFill([
                'status' => 'synced',
                'approximate_count' => count($identifiers),
                'last_synced_at' => now(),
                'last_error' => null,
            ])->save();

            Log::info('[tiktok] audience synced', [
                'sync_id' => $sync->id,
                'company_id' => $sync->company_id,
                'identifiers' => count($identifiers),
            ]);
        } catch (Throwable $exception) {
            $sync->forceFill(['status' => 'failed', 'last_error' => $exception->getMessage()])->save();
            $this->notifier->syncFailed($sync->connection, 'audience', $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * Hashed identifiers for the segment, one per line as TikTok expects.
     *
     * @return array<int, string>
     */
    private function identifiers(TikTokAudienceSync $sync): array
    {
        $usePhone = $sync->calculate_type === 'PHONE_SHA256';
        $identifiers = [];

        $this->segments
            ->segmentContactsQuery($sync->company_id, (array) ($sync->segment->criteria ?? []))
            ->select(['id', 'email', 'phone'])
            ->chunkById((int) config('tiktok.audience_batch_size', 1000), function ($contacts) use (&$identifiers, $usePhone) {
                foreach ($contacts as $contact) {
                    $hash = $usePhone
                        ? $this->hashing->hash($this->hashing->normalizePhone($contact->phone))
                        : $this->hashing->hash($this->hashing->normalizeEmail($contact->email));

                    if ($hash) {
                        $identifiers[] = $hash;
                    }
                }
            });

        return array_values(array_unique($identifiers));
    }

    /**
     * The upload is multipart rather than JSON, so it bypasses TikTokClient and
     * still has to interpret TikTok's in-body error code itself.
     *
     * @param  array<int, string>  $identifiers
     */
    private function uploadIdentifiers(TikTokAudienceSync $sync, array $identifiers): string
    {
        $url = rtrim((string) config('tiktok.base_url'), '/').'/'.config('tiktok.api_version').'/dmp/custom_audience/file/upload/';

        $response = Http::withHeaders(['Access-Token' => $sync->connection->access_token])
            ->timeout(120)
            ->attach('file', implode("\n", $identifiers), 'audience.csv')
            ->post($url, [
                'advertiser_id' => $sync->advertiser_id,
                'calculate_type' => $sync->calculate_type,
                'file_name' => 'audience.csv',
            ]);

        $body = $response->json() ?? [];

        if (! $response->successful() || (int) ($body['code'] ?? 0) !== 0) {
            throw TikTokApiException::fromBody($body, $response->status());
        }

        $filePath = $body['data']['file_path'] ?? null;

        if (! $filePath) {
            throw new TikTokApiException('TikTok did not return a file path for the uploaded audience.');
        }

        return $filePath;
    }

    private function createAudience(TikTokAudienceSync $sync, string $filePath): void
    {
        $data = (new TikTokClient($sync->connection))->post('dmp/custom_audience/create', [
            'advertiser_id' => $sync->advertiser_id,
            'custom_audience_name' => $sync->audience_name,
            'file_paths' => [$filePath],
            'calculate_type' => $sync->calculate_type,
        ]);

        $audienceId = $data['custom_audience_id'] ?? null;

        if (! $audienceId) {
            throw new TikTokApiException('TikTok did not return a custom audience id.');
        }

        $sync->forceFill(['tiktok_audience_id' => $audienceId])->save();
    }

    private function appendToAudience(TikTokAudienceSync $sync, string $filePath): void
    {
        (new TikTokClient($sync->connection))->post('dmp/custom_audience/update', [
            'advertiser_id' => $sync->advertiser_id,
            'custom_audience_id' => $sync->tiktok_audience_id,
            'action' => 'APPEND',
            'file_paths' => [$filePath],
        ]);
    }

    public function delete(TikTokAudienceSync $sync): void
    {
        if ($sync->tiktok_audience_id) {
            try {
                (new TikTokClient($sync->connection))->post('dmp/custom_audience/delete', [
                    'advertiser_id' => $sync->advertiser_id,
                    'custom_audience_ids' => [$sync->tiktok_audience_id],
                ]);
            } catch (TikTokApiException $exception) {
                // Already gone at TikTok, or no longer ours: local removal still stands.
                Log::warning('[tiktok] audience delete failed at TikTok', [
                    'sync_id' => $sync->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $sync->delete();
    }

    /**
     * Remove one contact from every audience it was pushed to — the deletion
     * counterpart used when a contact is erased locally.
     */
    public function removeContact(CRMContact $contact): void
    {
        $syncs = TikTokAudienceSync::where('company_id', $contact->company_id)
            ->whereNotNull('tiktok_audience_id')
            ->with('connection')
            ->get();

        foreach ($syncs as $sync) {
            if (! $sync->connection?->isConnected()) {
                continue;
            }

            $hash = $sync->calculate_type === 'PHONE_SHA256'
                ? $this->hashing->hash($this->hashing->normalizePhone($contact->phone))
                : $this->hashing->hash($this->hashing->normalizeEmail($contact->email));

            if (! $hash) {
                continue;
            }

            try {
                $filePath = $this->uploadIdentifiers($sync, [$hash]);

                (new TikTokClient($sync->connection))->post('dmp/custom_audience/update', [
                    'advertiser_id' => $sync->advertiser_id,
                    'custom_audience_id' => $sync->tiktok_audience_id,
                    'action' => 'REMOVE',
                    'file_paths' => [$filePath],
                ]);
            } catch (TikTokApiException $exception) {
                Log::warning('[tiktok] could not remove contact from audience', [
                    'sync_id' => $sync->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }
}
