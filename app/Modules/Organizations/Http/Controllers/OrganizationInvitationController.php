<?php

namespace App\Modules\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\Services\AuthenticationService;
use App\Modules\Organizations\Http\Requests\RegisterOrganizationInvitationRequest;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationInvitation;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Services\OrganizationInvitationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationInvitationController extends Controller
{
    public function __construct(
        private readonly OrganizationInvitationService $invitations,
        private readonly AuthenticationService $authentication,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        return ApiResponse::success(
            $this->invitations->list($request->user(), $organization, $request->integer('per_page', 25)),
            'Organization invitations loaded',
        );
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in([
                OrganizationMembership::ROLE_OWNER,
                OrganizationMembership::ROLE_ADMIN,
                OrganizationMembership::ROLE_BILLING_ADMIN,
                OrganizationMembership::ROLE_MEMBER,
            ])],
            'companies' => ['sometimes', 'array'],
            'companies.*.company_id' => ['required', 'integer', 'distinct', 'exists:companies,id'],
            'companies.*.role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'companies.*.custom_role_id' => ['nullable', 'integer', 'exists:company_custom_roles,id'],
        ]);

        return ApiResponse::success(
            $this->invitations->invite($request->user(), $organization, $data),
            'Organization invitation created',
            status: 201,
        );
    }

    public function show(string $token): JsonResponse
    {
        return ApiResponse::success($this->invitations->preview($token), 'Organization invitation loaded');
    }

    public function register(RegisterOrganizationInvitationRequest $request, string $token): JsonResponse
    {
        $data = $request->validated();
        $registration = $this->invitations->register($token, $data);

        return ApiResponse::success([
            'membership' => $registration['membership'],
            'auth' => $this->authentication->tokenResponse(
                $registration['user'],
                $data['device_name'] ?? 'api',
            ),
        ], 'Invitation account created and accepted', status: 201);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        return ApiResponse::success(
            $this->invitations->accept($request->user(), $token),
            'Organization invitation accepted',
        );
    }

    public function resend(
        Request $request,
        Organization $organization,
        OrganizationInvitation $invitation,
    ): JsonResponse {
        return ApiResponse::success(
            $this->invitations->resend($request->user(), $organization, $invitation),
            'Organization invitation renewed',
        );
    }

    public function destroy(
        Request $request,
        Organization $organization,
        OrganizationInvitation $invitation,
    ): JsonResponse {
        return ApiResponse::success(
            $this->invitations->cancel($request->user(), $organization, $invitation),
            'Organization invitation cancelled',
        );
    }
}
