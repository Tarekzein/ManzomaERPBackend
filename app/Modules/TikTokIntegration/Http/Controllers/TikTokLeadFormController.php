<?php

namespace App\Modules\TikTokIntegration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\Models\User;
use App\Modules\TikTokIntegration\Http\Requests\TikTokRequest;
use App\Modules\TikTokIntegration\Models\TikTokConnection;
use App\Modules\TikTokIntegration\Models\TikTokLeadFormMapping;
use App\Modules\TikTokIntegration\Policies\TikTokIntegrationPolicy;
use App\Modules\TikTokIntegration\Services\TikTokLeadService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TikTokLeadFormController extends Controller
{
    public function __construct(
        private readonly TikTokLeadService $leads,
        private readonly TikTokIntegrationPolicy $policy,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->policy->companyId($request->user());

        return ApiResponse::success(
            TikTokLeadFormMapping::where('company_id', $companyId)->get(),
            'TikTok lead form mappings loaded'
        );
    }

    /** Instant pages available on the advertiser, for building a mapping. */
    public function forms(Request $request): JsonResponse
    {
        $connection = $this->connection($request);
        $advertiserId = $request->string('advertiser_id') ?: $connection->default_advertiser_id;

        abort_unless($advertiserId, 422, 'Choose an advertiser account first.');

        return ApiResponse::success(
            $this->leads->forms($connection, (string) $advertiserId),
            'TikTok lead forms loaded'
        );
    }

    public function store(TikTokRequest $request): JsonResponse
    {
        $companyId = $this->policy->companyId($request->user(), 'tiktok.create');
        $connection = TikTokConnection::where('company_id', $companyId)->firstOrFail();
        $data = $request->validated();
        $this->ensureDefaultOwnerOwned($companyId, $data['default_owner_id'] ?? null);

        $mapping = TikTokLeadFormMapping::updateOrCreate(
            ['tiktok_connection_id' => $connection->id, 'page_id' => $request->string('page_id')],
            $data + ['company_id' => $companyId],
        );

        return ApiResponse::success($mapping, 'TikTok lead form mapping saved', status: 201);
    }

    public function update(TikTokRequest $request, TikTokLeadFormMapping $mapping): JsonResponse
    {
        $companyId = $this->policy->ensureOwned($request->user(), $mapping);
        $data = $request->validated();
        $this->ensureDefaultOwnerOwned($companyId, $data['default_owner_id'] ?? null);
        $mapping->update($data);

        return ApiResponse::success($mapping->fresh(), 'TikTok lead form mapping updated');
    }

    public function destroy(Request $request, TikTokLeadFormMapping $mapping): JsonResponse
    {
        $this->policy->ensureOwned($request->user(), $mapping, 'tiktok.delete');
        $mapping->delete();

        return ApiResponse::success(null, 'TikTok lead form mapping removed');
    }

    /** Kick the export along by hand rather than waiting for the schedule. */
    public function sync(Request $request, TikTokLeadFormMapping $mapping): JsonResponse
    {
        $this->policy->ensureOwned($request->user(), $mapping);
        $outcome = $this->leads->sync($mapping);

        return ApiResponse::success(
            ['outcome' => $outcome, 'mapping' => $mapping->fresh()],
            match ($outcome) {
                'requested' => 'Export requested from TikTok. Leads arrive once it is built.',
                'pending' => 'TikTok is still building the export.',
                'imported' => 'New leads imported.',
                'empty' => 'The export contained no new leads.',
                default => 'The export failed: '.($mapping->fresh()->last_error ?: 'unknown error'),
            }
        );
    }

    private function connection(Request $request, string $permission = 'tiktok.view'): TikTokConnection
    {
        $companyId = $this->policy->companyId($request->user(), $permission);

        return TikTokConnection::where('company_id', $companyId)->firstOrFail();
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
