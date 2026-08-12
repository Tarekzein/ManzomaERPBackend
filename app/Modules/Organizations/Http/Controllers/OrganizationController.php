<?php

namespace App\Modules\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Companies\DTOs\CreateCompanyData;
use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Services\OrganizationService;
use App\Modules\Organizations\Services\TenantSuspensionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationService $organizations,
        private readonly TenantSuspensionService $suspensions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->organizations->list($request->user(), $request->integer('per_page', 15)),
            'Organizations loaded',
        );
    }

    public function show(Request $request, Organization $organization): JsonResponse
    {
        return ApiResponse::success(
            $this->organizations->show($request->user(), $organization),
            'Organization loaded',
        );
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'billing_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'timezone' => ['sometimes', 'timezone'],
            'locale' => ['sometimes', 'string', 'max:8'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'settings' => ['sometimes', 'array'],
        ]);

        return ApiResponse::success(
            $this->organizations->update($request->user(), $organization, $data),
            'Organization updated',
        );
    }

    public function storeCompany(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'timezone' => ['required', 'timezone'],
            'locale' => ['required', 'string', 'max:8'],
            'currency' => ['required', 'string', 'size:3'],
        ]);

        return ApiResponse::success(
            $this->organizations->createCompany(
                $request->user(),
                $organization,
                new CreateCompanyData($data['name'], $data['timezone'], $data['locale'], $data['currency']),
            ),
            'Company created',
            status: 201,
        );
    }

    public function suspend(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);
        $this->suspensions->suspendOrganization($request->user(), $organization, $data['reason'] ?? null);

        return ApiResponse::success(
            $this->organizations->show($request->user(), $organization->refresh()),
            'Organization suspended',
        );
    }

    public function reactivate(Request $request, Organization $organization): JsonResponse
    {
        $this->suspensions->reactivateOrganization($request->user(), $organization);

        return ApiResponse::success(
            $this->organizations->show($request->user(), $organization->refresh()),
            'Organization reactivated',
        );
    }

    public function suspendCompany(Request $request, Organization $organization, Company $company): JsonResponse
    {
        abort_unless((int) $company->organization_id === (int) $organization->getKey(), 404);
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return ApiResponse::success(
            $this->suspensions->suspendCompany($request->user(), $company, $data['reason'] ?? null),
            'Company suspended',
        );
    }

    public function reactivateCompany(Request $request, Organization $organization, Company $company): JsonResponse
    {
        abort_unless((int) $company->organization_id === (int) $organization->getKey(), 404);

        return ApiResponse::success(
            $this->suspensions->reactivateCompany($request->user(), $company),
            'Company reactivated',
        );
    }

    public function archiveCompany(Request $request, Organization $organization, Company $company): JsonResponse
    {
        return ApiResponse::success(
            $this->organizations->archiveCompany($request->user(), $organization, $company),
            'Company archived',
        );
    }

    public function restoreCompany(Request $request, Organization $organization, Company $company): JsonResponse
    {
        return ApiResponse::success(
            $this->organizations->restoreCompany($request->user(), $organization, $company),
            'Company restored',
        );
    }
}
