<?php

namespace App\Modules\Platform\Services;

use App\Modules\CRM\Models\CRMContact;
use App\Modules\CRM\Models\CRMOpportunity;
use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\MetaIntegration\Models\MetaEventLog;
use App\Modules\MetaIntegration\Models\MetaPage;
use App\Modules\TikTokIntegration\Models\TikTokConnection;
use App\Modules\TikTokIntegration\Models\TikTokEventLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * One view of social performance across Meta and TikTok, assembled from data
 * this system already owns — leads with campaign attribution, opportunities
 * linked to those leads, and conversion-event delivery.
 *
 * Everything here is local: no ad-platform call is made, so the dashboard stays
 * fast and keeps working when a connection is down. Reach, impressions and
 * follower counts are *not* included — they only exist in the ad platforms and
 * need a live API call (TikTok: `report/integrated/get`; Meta: Insights, not yet
 * built).
 */
class SocialInsightsService
{
    private const CACHE_TTL_SECONDS = 300;

    /** @return array<string, mixed> */
    public function summary(int $companyId, int $days = 30): array
    {
        return Cache::remember(
            "social:insights:{$companyId}:{$days}",
            self::CACHE_TTL_SECONDS,
            fn () => $this->build($companyId, $days),
        );
    }

    public function forget(int $companyId): void
    {
        foreach ([7, 30, 90] as $days) {
            Cache::forget("social:insights:{$companyId}:{$days}");
        }
    }

    /** @return array<string, mixed> */
    private function build(int $companyId, int $days): array
    {
        $since = now()->subDays($days)->startOfDay();

        return [
            'period_days' => $days,
            'connections' => $this->connections($companyId),
            'leads' => $this->leads($companyId, $since),
            'campaigns' => $this->topCampaigns($companyId, $since),
            'pipeline' => $this->pipeline($companyId, $since),
            'conversions' => $this->conversionDelivery($companyId, $since),
            'generated_at' => now()->toISOString(),
        ];
    }

    /** Connection health for both platforms, so a broken integration is visible. */
    private function connections(int $companyId): array
    {
        $meta = MetaConnection::where('company_id', $companyId)->first();
        $tiktok = TikTokConnection::where('company_id', $companyId)->first();

        return [
            'meta' => [
                'status' => $meta?->status ?? 'not_connected',
                'healthy' => $meta?->status === 'connected',
                'expires_at' => $meta?->access_token_expires_at?->toISOString(),
                'pages' => $meta
                    ? MetaPage::where('meta_connection_id', $meta->id)->active()->count()
                    : 0,
                'pages_subscribed' => $meta
                    ? MetaPage::where('meta_connection_id', $meta->id)->active()->whereNotNull('webhook_subscribed_at')->count()
                    : 0,
                'last_error' => $meta?->last_error,
            ],
            'tiktok' => [
                'status' => $tiktok?->status ?? 'not_connected',
                'healthy' => $tiktok?->status === 'connected',
                'expires_at' => $tiktok?->access_token_expires_at?->toISOString(),
                'advertisers' => $tiktok ? $tiktok->advertisers()->where('is_active', true)->count() : 0,
                'last_error' => $tiktok?->last_error,
            ],
        ];
    }

    /** Leads attributed to each platform in the window. */
    private function leads(int $companyId, Carbon $since): array
    {
        $base = CRMContact::where('company_id', $companyId)->where('created_at', '>=', $since);

        $meta = (clone $base)->whereNotNull('meta_lead_id');
        $facebook = (clone $meta)->where(fn ($query) => $query->where('meta_platform', 'facebook')->orWhereNull('meta_platform'))->count();
        $instagram = (clone $meta)->where('meta_platform', 'instagram')->count();
        $tiktok = (clone $base)->whereNotNull('tiktok_lead_id')->count();
        $total = $facebook + $instagram + $tiktok;

        return [
            'total' => $total,
            'by_platform' => [
                'facebook' => $facebook,
                'instagram' => $instagram,
                'tiktok' => $tiktok,
            ],
            // Share of all new contacts that came from paid social.
            'share_of_new_contacts' => $this->share($total, (clone $base)->count()),
        ];
    }

    /**
     * Best-performing campaigns by lead volume, across both platforms.
     *
     * @return array<int, array<string, mixed>>
     */
    private function topCampaigns(int $companyId, Carbon $since, int $limit = 5): array
    {
        $meta = CRMContact::query()
            ->selectRaw("meta_campaign_id as campaign_id, 'meta' as platform, count(*) as leads")
            ->where('company_id', $companyId)
            ->where('created_at', '>=', $since)
            ->whereNotNull('meta_campaign_id')
            ->groupBy('meta_campaign_id');

        $tiktok = CRMContact::query()
            ->selectRaw("tiktok_campaign_id as campaign_id, 'tiktok' as platform, count(*) as leads")
            ->where('company_id', $companyId)
            ->where('created_at', '>=', $since)
            ->whereNotNull('tiktok_campaign_id')
            ->groupBy('tiktok_campaign_id');

        return $meta->unionAll($tiktok)
            ->orderByDesc('leads')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'campaign_id' => $row->campaign_id,
                'platform' => $row->platform,
                'leads' => (int) $row->leads,
            ])
            ->all();
    }

    /**
     * Revenue attribution: opportunities whose contact arrived from social.
     * One query with a join rather than walking contacts and loading their
     * opportunities.
     */
    private function pipeline(int $companyId, Carbon $since): array
    {
        $rows = CRMOpportunity::query()
            ->join('crm_contacts', 'crm_contacts.id', '=', 'crm_opportunities.contact_id')
            ->where('crm_opportunities.company_id', $companyId)
            ->where('crm_opportunities.created_at', '>=', $since)
            ->where(fn ($query) => $query
                ->whereNotNull('crm_contacts.meta_lead_id')
                ->orWhereNotNull('crm_contacts.tiktok_lead_id'))
            ->selectRaw('crm_opportunities.status, count(*) as total, coalesce(sum(crm_opportunities.value), 0) as amount')
            ->groupBy('crm_opportunities.status')
            ->get();

        $won = $rows->firstWhere('status', 'won');
        $openStatuses = $rows->whereNotIn('status', ['won', 'lost']);

        return [
            'opportunities' => (int) $rows->sum('total'),
            'open_value' => (float) $openStatuses->sum('amount'),
            'won_count' => (int) ($won->total ?? 0),
            'won_value' => (float) ($won->amount ?? 0),
            'conversion_rate' => $this->share((int) ($won->total ?? 0), (int) $rows->sum('total')),
            'by_status' => $rows->map(fn ($row) => [
                'status' => $row->status,
                'count' => (int) $row->total,
                'amount' => (float) $row->amount,
            ])->all(),
        ];
    }

    /** Whether conversion events are actually reaching the ad platforms. */
    private function conversionDelivery(int $companyId, Carbon $since): array
    {
        $meta = MetaEventLog::where('company_id', $companyId)
            ->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $tiktok = TikTokEventLog::where('company_id', $companyId)
            ->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'meta' => [
                'sent' => (int) ($meta['sent'] ?? 0),
                'pending' => (int) ($meta['pending'] ?? 0),
                'failed' => (int) ($meta['failed'] ?? 0),
            ],
            'tiktok' => [
                'sent' => (int) ($tiktok['sent'] ?? 0),
                'pending' => (int) ($tiktok['pending'] ?? 0),
                'failed' => (int) ($tiktok['failed'] ?? 0),
            ],
        ];
    }

    /**
     * Leads for one campaign — the drill-down behind the dashboard tiles.
     *
     * @return array<int, array<string, mixed>>
     */
    public function campaignLeads(int $companyId, string $platform, string $campaignId, int $limit = 100): array
    {
        $column = $platform === 'tiktok' ? 'tiktok_campaign_id' : 'meta_campaign_id';

        return CRMContact::query()
            ->where('company_id', $companyId)
            ->where($column, $campaignId)
            ->with('opportunities:id,contact_id,status,value')
            ->latest('created_at')
            ->limit($limit)
            ->get(['id', 'name', 'email', 'phone', 'status', 'source', 'created_at', $column])
            ->map(fn (CRMContact $contact) => [
                'id' => $contact->id,
                'name' => $contact->name,
                'email' => $contact->email,
                'status' => $contact->status,
                'created_at' => $contact->created_at?->toISOString(),
                'opportunity_value' => (float) $contact->opportunities->sum('value'),
            ])
            ->all();
    }

    private function share(int $part, int $whole): float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 1) : 0.0;
    }
}
