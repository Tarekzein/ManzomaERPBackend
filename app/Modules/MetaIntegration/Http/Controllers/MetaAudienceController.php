<?php

namespace App\Modules\MetaIntegration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CRMSegment;
use App\Modules\MetaIntegration\Http\Requests\MetaRequest;
use App\Modules\MetaIntegration\Models\MetaAudienceSync;
use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\MetaIntegration\Policies\MetaIntegrationPolicy;
use App\Modules\MetaIntegration\Services\MetaAudienceService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MetaAudienceController extends Controller
{
    public function __construct(private readonly MetaIntegrationPolicy $policy) {}

    public function index(Request $request)
    {
        $companyId = $this->policy->companyId($request->user());

        return ApiResponse::success(MetaAudienceSync::with('segment')->where('company_id', $companyId)->get());
    }

    public function store(MetaRequest $request, MetaAudienceService $audiences)
    {
        $companyId = $this->policy->companyId($request->user(), 'meta.create');
        $connection = MetaConnection::where('company_id', $companyId)->firstOrFail();
        $segment = CRMSegment::where('company_id', $companyId)->findOrFail($request->integer('crm_segment_id'));

        if (! $connection->business_id) {
            throw ValidationException::withMessages(['business_id' => ['Connect a Meta Business ID before creating audiences.']]);
        }

        $sync = MetaAudienceSync::create($request->validated() + [
            'company_id' => $companyId,
            'meta_connection_id' => $connection->id,
            'crm_segment_id' => $segment->id,
        ]);

        $sync = $audiences->createAudience($sync);

        return ApiResponse::success($sync, 'Custom audience created', status: 201);
    }

    public function update(MetaRequest $request, MetaAudienceSync $sync)
    {
        $companyId = $this->policy->ensureOwned($request->user(), $sync, 'meta.edit');
        $data = $request->validated();
        $this->ensureSegmentOwned($companyId, (int) $data['crm_segment_id']);
        $sync->update($data);

        return ApiResponse::success($sync->fresh(), 'Audience sync updated');
    }

    public function destroy(Request $request, MetaAudienceSync $sync)
    {
        $this->policy->ensureOwned($request->user(), $sync, 'meta.delete');
        $sync->delete();

        return ApiResponse::success(null, 'Audience sync removed');
    }

    public function sync(Request $request, MetaAudienceSync $sync, MetaAudienceService $audiences)
    {
        $this->policy->ensureOwned($request->user(), $sync, 'meta.edit');
        $audiences->sync($sync);

        return ApiResponse::success($sync->fresh(), 'Audience sync queued');
    }

    public function status(Request $request, MetaAudienceSync $sync)
    {
        $this->policy->ensureOwned($request->user(), $sync, 'meta.view');

        return ApiResponse::success($sync->fresh());
    }

    private function ensureSegmentOwned(int $companyId, int $segmentId): void
    {
        if (! CRMSegment::where('company_id', $companyId)->whereKey($segmentId)->exists()) {
            throw ValidationException::withMessages([
                'crm_segment_id' => ['Choose a CRM segment that belongs to this company.'],
            ]);
        }
    }
}
