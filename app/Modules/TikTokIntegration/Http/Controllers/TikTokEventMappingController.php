<?php

namespace App\Modules\TikTokIntegration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\TikTokIntegration\Http\Requests\TikTokRequest;
use App\Modules\TikTokIntegration\Jobs\SendTikTokEvent;
use App\Modules\TikTokIntegration\Models\TikTokConnection;
use App\Modules\TikTokIntegration\Models\TikTokEventLog;
use App\Modules\TikTokIntegration\Models\TikTokEventMapping;
use App\Modules\TikTokIntegration\Policies\TikTokIntegrationPolicy;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TikTokEventMappingController extends Controller
{
    public function __construct(private readonly TikTokIntegrationPolicy $policy) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->policy->companyId($request->user());

        return ApiResponse::success(
            TikTokEventMapping::where('company_id', $companyId)->get(),
            'TikTok event mappings loaded'
        );
    }

    public function store(TikTokRequest $request): JsonResponse
    {
        $companyId = $this->policy->companyId($request->user(), 'tiktok.create');
        $connection = TikTokConnection::where('company_id', $companyId)->firstOrFail();

        $mapping = TikTokEventMapping::updateOrCreate(
            ['company_id' => $companyId, 'trigger_source' => $request->string('trigger_source')],
            $request->validated() + ['tiktok_connection_id' => $connection->id],
        );

        return ApiResponse::success($mapping, 'TikTok event mapping saved', status: 201);
    }

    public function update(TikTokRequest $request, TikTokEventMapping $mapping): JsonResponse
    {
        $this->policy->ensureOwned($request->user(), $mapping);
        $mapping->update($request->validated());

        return ApiResponse::success($mapping->fresh(), 'TikTok event mapping updated');
    }

    public function destroy(Request $request, TikTokEventMapping $mapping): JsonResponse
    {
        $this->policy->ensureOwned($request->user(), $mapping, 'tiktok.delete');
        $mapping->delete();

        return ApiResponse::success(null, 'TikTok event mapping removed');
    }

    public function logs(Request $request): JsonResponse
    {
        $companyId = $this->policy->companyId($request->user());

        return ApiResponse::success(
            TikTokEventLog::where('company_id', $companyId)
                ->latest('id')
                ->paginate((int) $request->integer('per_page', 25)),
            'TikTok event logs loaded'
        );
    }

    public function retryLog(Request $request, TikTokEventLog $log): JsonResponse
    {
        $this->policy->ensureOwned($request->user(), $log);

        $log->forceFill(['status' => 'pending', 'next_retry_at' => null])->save();
        SendTikTokEvent::dispatch($log->id)->onQueue('tiktok-events');

        return ApiResponse::success($log->fresh(), 'TikTok event requeued');
    }
}
