<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Services\TenantSuspensionService;
use App\Modules\Platform\Services\CompanyContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCompanyContext
{
    public const HEADER = 'X-Manzoma-Workspace';

    public function __construct(
        private readonly CompanyContext $context,
        private readonly TenantSuspensionService $suspensions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->clear();
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $workspace = trim((string) $request->header(self::HEADER, ''));
        $routeWorkspace = $request->route('workspace');
        $routeWorkspace = $routeWorkspace instanceof Company
            ? $routeWorkspace->workspaceKey()
            : trim((string) $routeWorkspace);

        if ($workspace !== '' && $routeWorkspace !== '' && $workspace !== $routeWorkspace) {
            return $this->notFound();
        }

        $workspace = $workspace ?: $routeWorkspace;

        if ($user->isSuperAdmin()) {
            if ($workspace !== '') {
                $company = $this->findCompany($workspace);
                if (! $company) {
                    return $this->notFound();
                }

                $this->context->bind($user, $company);
            }

            return $this->continue($request, $next);
        }

        if ($workspace !== '') {
            $resolved = $this->resolveRequestedCompany($user, $workspace);
            if (! $resolved) {
                // A member whose access to this workspace was suspended is not
                // the same as a stranger: tell them, instead of hiding it.
                return $this->isSuspendedMemberOf($user, $workspace)
                    ? $this->suspended()
                    : $this->notFound();
            }

            [$company, $membership, $organizationMembership] = $resolved;
            $this->context->bind($user, $company, $membership, $organizationMembership);

            return $this->continue($request, $next);
        }

        $memberships = $user->companyMemberships()
            ->where('status', 'active')
            ->whereHas('company', fn ($query) => $query->whereNull('archived_at'))
            ->whereHas('organization', fn ($query) => $query->where('status', '!=', 'archived'))
            ->whereHas('organization.memberships', fn ($query) => $query
                ->where('user_id', $user->getKey())
                ->where('status', 'active'))
            ->with(['company.organization', 'role.permissions', 'customRole', 'permissionOverrides'])
            ->get();

        if ($memberships->count() > 1) {
            if ($this->requiresWorkspace($request)) {
                return $this->workspaceRequired();
            }

            return $this->continue($request, $next);
        }

        if ($membership = $memberships->first()) {
            $company = $membership->company;
            $organizationMembership = $this->activeOrganizationMembership($user, $company);

            if (! $this->organizationAccessIsValid($company, $organizationMembership)) {
                return $this->notFound();
            }

            $this->context->bind($user, $company, $membership, $organizationMembership);

            return $this->continue($request, $next);
        }

        // Once memberships exist, they are authoritative. Falling back to the
        // legacy user.company_id here would let a suspended membership regain
        // access simply by omitting the workspace header.
        if ($user->companyMemberships()->exists()) {
            if (! $this->requiresWorkspace($request)) {
                return $this->continue($request, $next);
            }

            // Every workspace this user has is closed to them. Say so, rather
            // than pretending the workspace does not exist.
            return $this->suspensions->stateFor($user)
                ? $this->suspended()
                : $this->notFound();
        }

        // Compatibility for rows/clients not yet dual-written to memberships.
        $user->loadMissing('company.organization');
        if ($user->company && $user->company->archived_at === null) {
            $this->context->bind($user, $user->company);
        }

        return $this->continue($request, $next);
    }

    private function resolveRequestedCompany(User $user, string $workspace): ?array
    {
        $company = $this->findCompany($workspace);
        if (! $company) {
            return null;
        }

        $membership = $user->companyMemberships()
            ->where('company_id', $company->getKey())
            ->where('status', 'active')
            ->with(['role.permissions', 'customRole', 'permissionOverrides'])
            ->first();
        $organizationMembership = $this->activeOrganizationMembership($user, $company);

        if ($membership && $this->organizationAccessIsValid($company, $organizationMembership)) {
            return [$company, $membership, $organizationMembership];
        }

        $legacy = ! $user->companyMemberships()->exists() && (int) $user->company_id === (int) $company->getKey();

        return $legacy ? [$company, null, null] : null;
    }

    private function findCompany(string $workspace): ?Company
    {
        return Company::query()
            ->with('organization')
            ->where(function ($query) use ($workspace) {
                $query->where('slug', $workspace);

                if (ctype_digit($workspace)) {
                    $query->orWhere('id', (int) $workspace);
                }
            })
            ->whereNull('archived_at')
            ->first();
    }

    private function activeOrganizationMembership(User $user, Company $company): ?OrganizationMembership
    {
        if (! $company->organization_id) {
            return null;
        }

        return $user->organizationMemberships()
            ->where('organization_id', $company->organization_id)
            ->where('status', 'active')
            ->first();
    }

    private function organizationAccessIsValid(Company $company, ?OrganizationMembership $membership): bool
    {
        if (! $company->organization_id) {
            return true;
        }

        return $membership !== null && $company->organization?->status !== 'archived';
    }

    private function requiresWorkspace(Request $request): bool
    {
        return ! $request->is(
            'api/auth/*',
            'api/organizations',
            'api/organizations/*',
            'api/organization-invitations/*',
            'api/workspace/*',
            'api/system/*',
        );
    }

    private function continue(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }

    private function workspaceRequired(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'WORKSPACE_REQUIRED',
            'data' => null,
            'message' => 'Select a company workspace before continuing.',
            'errors' => (object) [],
            'meta' => (object) [],
        ], 409);
    }

    /** Does this user hold a membership in that workspace that is closed to them? */
    private function isSuspendedMemberOf(User $user, string $workspace): bool
    {
        $company = $this->findCompany($workspace);

        return $company !== null && $this->suspensions->companyState($user, $company) !== null;
    }

    private function suspended(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'ACCOUNT_SUSPENDED',
            'data' => null,
            'message' => 'Your access has been suspended.',
            'errors' => (object) [],
            'meta' => (object) [],
        ], 403);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'WORKSPACE_NOT_FOUND',
            'data' => null,
            'message' => 'The requested workspace is unavailable.',
            'errors' => (object) [],
            'meta' => (object) [],
        ], 404);
    }
}
