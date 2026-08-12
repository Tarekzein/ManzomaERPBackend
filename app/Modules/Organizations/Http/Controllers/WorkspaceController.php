<?php

namespace App\Modules\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\CompanyMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Services\OrganizationAccessService;
use App\Modules\Organizations\Services\OrganizationService;
use App\Modules\Platform\Http\Middleware\ResolveCompanyContext;
use App\Modules\Platform\Services\CompanyContext;
use App\Modules\Subscriptions\Services\OrganizationEntitlementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly OrganizationService $organizations,
        private readonly OrganizationAccessService $access,
        private readonly OrganizationEntitlementService $entitlements,
    ) {}

    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();
        $activeCompany = $this->context->company();

        $organizations = Organization::query()
            ->when(! $user->isSuperAdmin(), fn ($query) => $query->whereHas(
                'memberships',
                fn ($membership) => $membership
                    ->where('user_id', $user->getKey())
                    ->where('status', OrganizationMembership::STATUS_ACTIVE)
            ))
            ->whereNull('archived_at')
            ->with([
                'memberships' => fn ($query) => $query
                    ->where('user_id', $user->getKey())
                    ->where('status', OrganizationMembership::STATUS_ACTIVE),
                'companies' => fn ($query) => $query
                    ->whereNull('archived_at')
                    ->where('is_active', true)
                    ->when(! $user->isSuperAdmin(), fn ($companies) => $companies->whereHas(
                        'companyMemberships',
                        fn ($memberships) => $memberships
                            ->where('user_id', $user->getKey())
                            ->where('status', CompanyMembership::STATUS_ACTIVE)
                    ))
                    ->orderBy('name'),
            ])
            ->orderBy('name')
            ->get();

        $companies = $organizations
            ->flatMap(fn (Organization $organization) => $organization->companies);
        if ($activeCompany) {
            $companies->push($activeCompany);
        }
        $this->entitlements->projectCompanySubscriptions(
            $companies->values(),
        );

        // The switcher only needs to name each organization. Organization-level
        // data stays behind the organization roles.
        $organizations = $organizations->map(fn (Organization $organization) => $this->access->project(
            $user,
            $organization,
            $organization->relationLoaded('memberships') ? $organization->memberships->first() : null,
        ));
        $activeOrganization = $activeCompany?->organization;

        return ApiResponse::success([
            'active_company' => $activeCompany,
            'active_organization' => $activeOrganization
                ? $this->access->project($user, $activeOrganization, $this->context->organizationMembership())
                : null,
            'default_company_id' => $user->default_company_id,
            'organizations' => $organizations,
            'workspace_header' => ResolveCompanyContext::HEADER,
        ], 'Workspace bootstrap loaded');
    }

    public function setDefault(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'exists:companies,id']]);
        $company = Company::query()->findOrFail($data['company_id']);

        return ApiResponse::success(
            $this->organizations->setDefaultCompany($request->user(), $company),
            'Default workspace updated',
        );
    }
}
