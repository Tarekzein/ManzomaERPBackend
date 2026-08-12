<?php

namespace App\Modules\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Services\OrganizationMembershipService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationMemberController extends Controller
{
    public function __construct(private readonly OrganizationMembershipService $memberships) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        return ApiResponse::success(
            $this->memberships->list($request->user(), $organization, $request->integer('per_page', 25)),
            'Organization members loaded',
        );
    }

    public function update(
        Request $request,
        Organization $organization,
        OrganizationMembership $membership,
    ): JsonResponse {
        $data = $request->validate([
            'role' => ['sometimes', Rule::in([
                OrganizationMembership::ROLE_OWNER,
                OrganizationMembership::ROLE_ADMIN,
                OrganizationMembership::ROLE_BILLING_ADMIN,
                OrganizationMembership::ROLE_MEMBER,
            ])],
            'status' => ['sometimes', Rule::in([
                OrganizationMembership::STATUS_ACTIVE,
                OrganizationMembership::STATUS_SUSPENDED,
                OrganizationMembership::STATUS_REMOVED,
            ])],
        ]);

        return ApiResponse::success(
            $this->memberships->update($request->user(), $organization, $membership, $data),
            'Organization membership updated',
        );
    }

    public function assignCompany(
        Request $request,
        Organization $organization,
        OrganizationMembership $membership,
        Company $company,
    ): JsonResponse {
        $data = $request->validate([
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'custom_role_id' => ['nullable', 'integer', 'exists:company_custom_roles,id'],
            'permission_overrides' => ['sometimes', 'array'],
            'permission_overrides.*.permission_name' => ['required', 'string', 'exists:permissions,name'],
            'permission_overrides.*.effect' => ['required', Rule::in(['allow', 'deny'])],
        ]);

        return ApiResponse::success(
            $this->memberships->assignCompany($request->user(), $organization, $membership, $company, $data),
            'Company membership saved',
        );
    }

    public function removeCompany(
        Request $request,
        Organization $organization,
        OrganizationMembership $membership,
        Company $company,
    ): JsonResponse {
        return ApiResponse::success(
            $this->memberships->removeCompany($request->user(), $organization, $membership, $company),
            'Company membership removed',
        );
    }
}
