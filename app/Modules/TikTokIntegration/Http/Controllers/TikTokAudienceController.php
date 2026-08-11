<?php

namespace App\Modules\TikTokIntegration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CRMSegment;
use App\Modules\TikTokIntegration\Http\Requests\TikTokRequest;
use App\Modules\TikTokIntegration\Models\TikTokAudienceSync;
use App\Modules\TikTokIntegration\Models\TikTokConnection;
use App\Modules\TikTokIntegration\Policies\TikTokIntegrationPolicy;
use App\Modules\TikTokIntegration\Services\TikTokAudienceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TikTokAudienceController extends Controller
{
    public function __construct(
        private readonly TikTokAudienceService $audiences,
        private readonly TikTokIntegrationPolicy $policy,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->policy->companyId($request->user());

        return ApiResponse::success(
            TikTokAudienceSync::with('segment:id,name')->where('company_id', $companyId)->get(),
            'TikTok audiences loaded'
        );
    }

    public function store(TikTokRequest $request): JsonResponse
    {
        $companyId = $this->policy->companyId($request->user(), 'tiktok.create');
        $connection = TikTokConnection::where('company_id', $companyId)->firstOrFail();
        $data = $request->validated();
        $this->ensureSegmentOwned($companyId, (int) $data['crm_segment_id']);

        // Request::string() returns a Stringable object, which is truthy even
        // when it contains an empty string. Cast before applying the fallback.
        $advertiserId = trim((string) ($data['advertiser_id'] ?? '')) ?: $connection->default_advertiser_id;

        abort_unless($advertiserId, 422, 'Choose an advertiser account first.');

        $sync = TikTokAudienceSync::updateOrCreate(
            [
                'company_id' => $companyId,
                'crm_segment_id' => (int) $data['crm_segment_id'],
                'tiktok_connection_id' => $connection->id,
            ],
            array_merge($data, ['advertiser_id' => (string) $advertiserId]),
        );

        return ApiResponse::success($sync->load('segment:id,name'), 'TikTok audience saved', status: 201);
    }

    public function update(TikTokRequest $request, TikTokAudienceSync $sync): JsonResponse
    {
        $companyId = $this->policy->ensureOwned($request->user(), $sync);
        $data = $request->validated();
        $this->ensureSegmentOwned($companyId, (int) $data['crm_segment_id']);

        if (array_key_exists('advertiser_id', $data) && trim((string) $data['advertiser_id']) === '') {
            $data['advertiser_id'] = $sync->connection->default_advertiser_id ?: $sync->advertiser_id;
        }

        $sync->update($data);

        return ApiResponse::success($sync->fresh()->load('segment:id,name'), 'TikTok audience updated');
    }

    public function destroy(Request $request, TikTokAudienceSync $sync): JsonResponse
    {
        $this->policy->ensureOwned($request->user(), $sync, 'tiktok.delete');
        $this->audiences->delete($sync);

        return ApiResponse::success(null, 'TikTok audience removed');
    }

    public function sync(Request $request, TikTokAudienceSync $sync): JsonResponse
    {
        $this->policy->ensureOwned($request->user(), $sync);
        $this->audiences->queueSync($sync);

        return ApiResponse::success($sync->fresh(), 'TikTok audience sync queued');
    }

    private function ensureSegmentOwned(int $companyId, int $segmentId): void
    {
        abort_unless(
            CRMSegment::where('company_id', $companyId)->whereKey($segmentId)->exists(),
            422,
            'Choose a CRM segment that belongs to this company.'
        );
    }
}
