<?php

namespace App\Modules\MetaIntegration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\Models\User;
use App\Modules\MetaIntegration\Http\Requests\MetaRequest;
use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\MetaIntegration\Models\MetaLeadFormMapping;
use App\Modules\MetaIntegration\Policies\MetaIntegrationPolicy;
use App\Modules\MetaIntegration\Services\MetaLeadAdsService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MetaLeadFormController extends Controller
{
    public function __construct(private readonly MetaIntegrationPolicy $policy) {}

    public function index(Request $request)
    {
        $companyId = $this->policy->companyId($request->user());

        return ApiResponse::success(MetaLeadFormMapping::where('company_id', $companyId)->get());
    }

    public function store(MetaRequest $request)
    {
        $companyId = $this->policy->companyId($request->user(), 'meta.create');
        $connection = MetaConnection::where('company_id', $companyId)->firstOrFail();
        $data = $request->validated();
        $this->ensureDefaultOwnerOwned($companyId, $data['default_owner_id'] ?? null);

        $mapping = MetaLeadFormMapping::updateOrCreate(
            ['company_id' => $companyId, 'form_id' => $request->string('form_id')],
            $data + ['company_id' => $companyId, 'meta_connection_id' => $connection->id],
        );

        return ApiResponse::success($mapping, 'Lead form mapping saved', status: 201);
    }

    public function update(MetaRequest $request, MetaLeadFormMapping $mapping)
    {
        $companyId = $this->policy->ensureOwned($request->user(), $mapping, 'meta.edit');
        $data = $request->validated();
        $this->ensureDefaultOwnerOwned($companyId, $data['default_owner_id'] ?? null);
        $mapping->update($data);

        return ApiResponse::success($mapping->fresh(), 'Lead form mapping updated');
    }

    public function destroy(Request $request, MetaLeadFormMapping $mapping)
    {
        $this->policy->ensureOwned($request->user(), $mapping, 'meta.delete');
        $mapping->delete();

        return ApiResponse::success(null, 'Lead form mapping deleted');
    }

    public function backfill(Request $request, MetaLeadFormMapping $mapping, MetaLeadAdsService $leadAds)
    {
        $this->policy->ensureOwned($request->user(), $mapping, 'meta.edit');
        $imported = $leadAds->backfill($mapping);

        return ApiResponse::success(['imported' => $imported], "Imported {$imported} historical leads");
    }

    private function ensureDefaultOwnerOwned(int $companyId, ?int $ownerId): void
    {
        if ($ownerId === null) {
            return;
        }

        if (! User::where('company_id', $companyId)->whereKey($ownerId)->exists()) {
            throw ValidationException::withMessages([
                'default_owner_id' => ['Choose a default owner that belongs to this company.'],
            ]);
        }
    }
}
