<?php

namespace App\Modules\TikTokIntegration\Services;

use App\Modules\TikTokIntegration\Exceptions\TikTokApiException;
use App\Modules\TikTokIntegration\Models\TikTokAdvertiser;
use App\Modules\TikTokIntegration\Models\TikTokConnection;
use Illuminate\Support\Collection;

/**
 * Advertiser (ad account) discovery. Everything else in the Marketing API is
 * scoped to an advertiser id, so this is the first thing a connection needs.
 */
class TikTokAdvertiserService
{
    public function __construct(private readonly TikTokConnectionNotifier $notifier) {}

    /** @return Collection<int, TikTokAdvertiser> */
    public function sync(TikTokConnection $connection): Collection
    {
        $client = new TikTokClient($connection);

        try {
            // The authorised list first, then details for the ones we can see.
            $authorised = $client->get('oauth2/advertiser/get', [
                'app_id' => $connection->appId(),
                'secret' => $connection->appSecret(),
            ]);

            $ids = collect($authorised['list'] ?? [])->pluck('advertiser_id')->filter()->values();

            $details = $ids->isEmpty() ? [] : ($client->get('advertiser/info', [
                'advertiser_ids' => $ids->all(),
                'fields' => ['advertiser_id', 'advertiser_name', 'currency', 'timezone', 'status'],
            ])['list'] ?? []);
        } catch (TikTokApiException $exception) {
            $this->notifier->syncFailed($connection, 'advertiser', $exception->getMessage());

            throw $exception;
        }

        $byId = collect($details)->keyBy('advertiser_id');
        $seen = [];

        foreach ($authorised['list'] ?? [] as $entry) {
            $id = $entry['advertiser_id'] ?? null;
            if (! $id) {
                continue;
            }

            $seen[] = $id;
            $detail = $byId->get($id, []);

            TikTokAdvertiser::updateOrCreate(
                ['tiktok_connection_id' => $connection->id, 'advertiser_id' => $id],
                [
                    'company_id' => $connection->company_id,
                    'name' => $detail['advertiser_name'] ?? $entry['advertiser_name'] ?? null,
                    'currency' => $detail['currency'] ?? null,
                    'timezone' => $detail['timezone'] ?? null,
                    'status' => $detail['status'] ?? null,
                    'is_active' => true,
                    'synced_at' => now(),
                ],
            );
        }

        // Revoked accounts stay for history but drop out of pickers.
        TikTokAdvertiser::where('tiktok_connection_id', $connection->id)
            ->when($seen, fn ($query) => $query->whereNotIn('advertiser_id', $seen))
            ->update(['is_active' => false]);

        if (! $connection->default_advertiser_id && $seen) {
            $connection->forceFill(['default_advertiser_id' => $seen[0]])->save();
        }

        return TikTokAdvertiser::where('tiktok_connection_id', $connection->id)->active()->get();
    }

    /** Campaign performance for reporting and attribution. */
    public function campaignReport(TikTokConnection $connection, string $advertiserId, string $since, string $until): array
    {
        return (new TikTokClient($connection))->get('report/integrated/get', [
            'advertiser_id' => $advertiserId,
            'report_type' => 'BASIC',
            'data_level' => 'AUCTION_CAMPAIGN',
            'dimensions' => ['campaign_id'],
            'metrics' => ['campaign_name', 'spend', 'impressions', 'clicks', 'ctr', 'conversion', 'cost_per_conversion'],
            'start_date' => $since,
            'end_date' => $until,
            'page_size' => 100,
        ])['list'] ?? [];
    }
}
