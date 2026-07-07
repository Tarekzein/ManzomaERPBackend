<?php

namespace App\Modules\MetaIntegration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MetaIntegration\Http\Requests\MetaRequest;
use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\MetaIntegration\Models\MetaEventLog;
use App\Modules\MetaIntegration\Models\MetaEventMapping;
use App\Modules\MetaIntegration\Policies\MetaIntegrationPolicy;
use App\Modules\MetaIntegration\Services\MetaConversionService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class MetaEventMappingController extends Controller
{
    public function __construct(private readonly MetaIntegrationPolicy $policy) {}

    public function index(Request $request)
    {
        $companyId = $this->policy->companyId($request->user());

        return ApiResponse::success(MetaEventMapping::where('company_id', $companyId)->get());
    }

    public function store(MetaRequest $request)
    {
        $companyId = $this->policy->companyId($request->user(), 'meta.create');
        $connection = MetaConnection::where('company_id', $companyId)->firstOrFail();

        $mapping = MetaEventMapping::updateOrCreate(
            ['company_id' => $companyId, 'trigger_source' => $request->string('trigger_source')],
            $request->validated() + ['company_id' => $companyId, 'meta_connection_id' => $connection->id],
        );

        return ApiResponse::success($mapping, 'Event mapping saved', status: 201);
    }

    public function update(MetaRequest $request, MetaEventMapping $mapping)
    {
        $this->policy->ensureOwned($request->user(), $mapping, 'meta.edit');
        $mapping->update($request->validated());

        return ApiResponse::success($mapping->fresh(), 'Event mapping updated');
    }

    public function destroy(Request $request, MetaEventMapping $mapping)
    {
        $this->policy->ensureOwned($request->user(), $mapping, 'meta.delete');
        $mapping->delete();

        return ApiResponse::success(null, 'Event mapping deleted');
    }

    public function logs(Request $request)
    {
        $companyId = $this->policy->companyId($request->user());

        $logs = MetaEventLog::where('company_id', $companyId)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('trigger_source'), fn ($q) => $q->where('trigger_source', $request->string('trigger_source')))
            ->latest()
            ->paginate((int) $request->integer('per_page', 25));

        return ApiResponse::success($logs);
    }

    public function retryLog(Request $request, MetaEventLog $log, MetaConversionService $conversions)
    {
        $this->policy->ensureOwned($request->user(), $log, 'meta.edit');
        $conversions->retry($log);

        return ApiResponse::success($log->fresh(), 'Event queued for retry');
    }
}
