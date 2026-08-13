<?php

namespace App\Modules\Organizations\Services;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\CompanyCustomRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Organizations\Models\CompanyMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationInvitation;
use App\Modules\Organizations\Models\OrganizationInvitationCompany;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Subscriptions\Services\OrganizationQuotaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class OrganizationInvitationService
{
    public function __construct(
        private readonly OrganizationAccessService $access,
        private readonly OrganizationQuotaService $quotas,
    ) {}

    public function list(User $actor, Organization $organization, int $perPage = 25)
    {
        $this->access->ensureCanManage($actor, $organization);

        return $organization->invitations()
            ->with(['companies.company', 'companies.role', 'companies.customRole', 'invitedBy'])
            ->latest()
            ->paginate(min(max($perPage, 1), 100));
    }

    /** @return array{invitation: OrganizationInvitation, token: string} */
    public function invite(User $actor, Organization $organization, array $data): array
    {
        $actorMembership = $this->access->ensureCanManage($actor, $organization);
        $role = $data['role'] ?? OrganizationMembership::ROLE_MEMBER;

        if (! $actor->isSuperAdmin()
            && $role === OrganizationMembership::ROLE_OWNER
            && $actorMembership?->role !== OrganizationMembership::ROLE_OWNER) {
            throw new AuthorizationException('Only an organization owner can invite another owner.');
        }

        $email = Str::lower(trim($data['email']));
        $existingUser = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($role === OrganizationMembership::ROLE_BILLING_ADMIN && empty($data['companies'])) {
            throw ValidationException::withMessages([
                'companies' => ['Assign a billing administrator to at least one company workspace.'],
            ]);
        }

        return $this->quotas->withinInvitationReservation(
            $organization,
            $existingUser,
            fn (Organization $locked) => OrganizationInvitation::query()
                ->where('organization_id', $locked->getKey())
                ->whereRaw('LOWER(email) = ?', [$email])
                ->where('status', OrganizationInvitation::STATUS_PENDING)
                ->latest('id')
                ->lockForUpdate()
                ->first(),
            function (Organization $locked, ?OrganizationInvitation $invitation) use ($actor, $existingUser, $email, $role, $data) {
                $this->ensureUserIsNotActiveMember($existingUser, $locked, 'email');

                return $this->persistInvitation(
                    $actor,
                    $locked,
                    $invitation,
                    $email,
                    $role,
                    $data['companies'] ?? [],
                );
            },
        );
    }

    public function preview(string $token): OrganizationInvitation
    {
        $invitation = $this->findByToken($token);
        $this->expireIfNeeded($invitation);

        $invitation = $invitation->refresh()->load(['organization', 'companies.company']);
        $invitation->setAttribute('requires_registration', ! User::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower($invitation->email)])
            ->exists());

        return $invitation;
    }

    public function accept(User $user, string $token): OrganizationMembership
    {
        $invitation = $this->findByToken($token);
        $this->expireIfNeeded($invitation);

        if ($invitation->status !== OrganizationInvitation::STATUS_PENDING) {
            throw ValidationException::withMessages(['invitation' => ['This invitation is no longer available.']]);
        }

        if (Str::lower($user->email) !== Str::lower($invitation->email)) {
            throw new AuthorizationException('This invitation was issued to another email address.');
        }

        return $this->quotas->withinInvitationAcceptance(
            $invitation->organization()->firstOrFail(),
            $user,
            function (Organization $organization) use ($user, $invitation) {
                $invitation = OrganizationInvitation::query()
                    ->whereKey($invitation->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                return $this->acceptLocked($user, $organization, $invitation);
            },
        );
    }

    /**
     * Create an account for a new invitee and convert the reserved invitation
     * seat into active organization/company memberships in one transaction.
     *
     * @return array{user: User, membership: OrganizationMembership}
     */
    public function register(string $token, array $data): array
    {
        $invitation = $this->findByToken($token);
        $this->expireIfNeeded($invitation);

        if ($invitation->status !== OrganizationInvitation::STATUS_PENDING) {
            throw ValidationException::withMessages(['invitation' => ['This invitation is no longer available.']]);
        }

        return $this->quotas->withinInvitationAcceptance(
            $invitation->organization()->firstOrFail(),
            null,
            function (Organization $organization) use ($invitation, $data) {
                $invitation = OrganizationInvitation::query()
                    ->whereKey($invitation->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($invitation->expires_at->isPast()
                    || $invitation->status !== OrganizationInvitation::STATUS_PENDING) {
                    throw ValidationException::withMessages([
                        'invitation' => ['This invitation is no longer available.'],
                    ]);
                }

                $email = Str::lower(trim($invitation->email));
                if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                    throw ValidationException::withMessages([
                        'email' => ['An account already exists for this invitation. Sign in to continue.'],
                    ]);
                }

                try {
                    $user = User::query()->create([
                        'company_id' => null,
                        'default_company_id' => null,
                        'name' => trim($data['name']),
                        'email' => $email,
                        'password' => Hash::make($data['password']),
                        'is_active' => true,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    throw ValidationException::withMessages([
                        'email' => ['An account already exists for this invitation. Sign in to continue.'],
                    ]);
                }

                return [
                    'user' => $user,
                    'membership' => $this->acceptLocked($user, $organization, $invitation),
                ];
            },
        );
    }

    /** @return array{invitation: OrganizationInvitation, token: string} */
    public function resend(User $actor, Organization $organization, OrganizationInvitation $invitation): array
    {
        $this->access->ensureCanManage($actor, $organization);
        $this->ensureInvitationBelongsTo($organization, $invitation);

        $existingUser = User::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower($invitation->email)])
            ->first();

        return $this->quotas->withinInvitationReservation(
            $organization,
            $existingUser,
            fn (Organization $locked) => OrganizationInvitation::query()
                ->where('organization_id', $locked->getKey())
                ->whereKey($invitation->getKey())
                ->lockForUpdate()
                ->firstOrFail(),
            function (Organization $organization, ?OrganizationInvitation $locked) use ($existingUser) {
                abort_unless($locked, 404);

                if ($locked->status !== OrganizationInvitation::STATUS_PENDING) {
                    throw ValidationException::withMessages(['invitation' => ['Only pending invitations can be resent.']]);
                }

                $this->ensureUserIsNotActiveMember($existingUser, $organization, 'invitation');
                $token = Str::random(64);
                $locked->forceFill([
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => now()->addDays(7),
                ])->save();
                $this->quotas->reconcile($organization);

                return ['invitation' => $locked->refresh(), 'token' => $token];
            },
        );
    }

    public function cancel(User $actor, Organization $organization, OrganizationInvitation $invitation): OrganizationInvitation
    {
        $this->access->ensureCanManage($actor, $organization);
        $this->ensureInvitationBelongsTo($organization, $invitation);

        if ($invitation->status === OrganizationInvitation::STATUS_PENDING) {
            $invitation->forceFill([
                'status' => OrganizationInvitation::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ])->save();
            $this->quotas->reconcile($organization);
        }

        return $invitation->refresh();
    }

    /** @return array{invitation: OrganizationInvitation, token: string} */
    private function persistInvitation(
        User $actor,
        Organization $organization,
        ?OrganizationInvitation $invitation,
        string $email,
        string $role,
        array $assignments,
    ): array {
        $token = Str::random(64);
        $invitation ??= new OrganizationInvitation([
            'organization_id' => $organization->getKey(),
            'email' => $email,
        ]);
        $invitation->forceFill([
            'token_hash' => hash('sha256', $token),
            'role' => $role,
            'status' => OrganizationInvitation::STATUS_PENDING,
            'invited_by_user_id' => $actor->getKey(),
            'expires_at' => now()->addDays(7),
            'accepted_by_user_id' => null,
            'accepted_at' => null,
            'cancelled_at' => null,
        ])->save();

        $invitation->companies()->delete();
        foreach ($assignments as $assignment) {
            if (! empty($assignment['role_id']) && ! empty($assignment['custom_role_id'])) {
                throw ValidationException::withMessages([
                    'companies' => ['Choose either a fixed role or a custom role for each company, not both.'],
                ]);
            }

            $company = $organization->companies()
                ->whereNull('archived_at')
                ->findOrFail($assignment['company_id']);
            $roleModel = isset($assignment['role_id']) ? Role::query()->findOrFail($assignment['role_id']) : null;
            $customRole = isset($assignment['custom_role_id'])
                ? CompanyCustomRole::query()->where('company_id', $company->getKey())->findOrFail($assignment['custom_role_id'])
                : null;

            if ($roleModel && ! in_array($roleModel->name, UserRole::workspaceAssignableValues(), true)) {
                throw ValidationException::withMessages([
                    'companies' => ['Only approved workspace role templates can be assigned to a company.'],
                ]);
            }

            if (! $roleModel && ! $customRole) {
                $roleModel = Role::findByName(UserRole::Employee->value, 'web');
            }

            OrganizationInvitationCompany::query()->create([
                'organization_id' => $organization->getKey(),
                'organization_invitation_id' => $invitation->getKey(),
                'company_id' => $company->getKey(),
                'role_id' => $roleModel?->getKey(),
                'custom_role_id' => $customRole?->getKey(),
            ]);
        }

        $this->quotas->reconcile($organization);

        return [
            'invitation' => $invitation->refresh()->load(['companies.company', 'companies.role', 'companies.customRole']),
            'token' => $token,
        ];
    }

    private function acceptLocked(
        User $user,
        Organization $organization,
        OrganizationInvitation $invitation,
    ): OrganizationMembership {
        if ($invitation->expires_at->isPast()
            || $invitation->status !== OrganizationInvitation::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'invitation' => ['This invitation is no longer available.'],
            ]);
        }

        if ($organization->status === Organization::STATUS_ARCHIVED || $organization->archived_at !== null) {
            throw ValidationException::withMessages([
                'invitation' => ['This invitation belongs to an archived organization.'],
            ]);
        }

        if (Str::lower($user->email) !== Str::lower($invitation->email)) {
            throw new AuthorizationException('This invitation was issued to another email address.');
        }

        $assignments = $invitation->companies()->with(['company', 'role'])->get();
        if ($assignments->contains(fn (OrganizationInvitationCompany $assignment) => $assignment->company === null
            || (int) $assignment->company->organization_id !== (int) $organization->getKey()
            || $assignment->company->archived_at !== null
        )) {
            throw ValidationException::withMessages([
                'invitation' => ['One or more assigned company workspaces are no longer available.'],
            ]);
        }

        if (OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', OrganizationMembership::STATUS_ACTIVE)
            ->exists()) {
            throw ValidationException::withMessages([
                'invitation' => ['This user is already an active organization member.'],
            ]);
        }

        $invitation->forceFill([
            'status' => OrganizationInvitation::STATUS_ACCEPTED,
            'accepted_by_user_id' => $user->getKey(),
            'accepted_at' => now(),
        ])->save();

        $membership = OrganizationMembership::query()->updateOrCreate(
            ['organization_id' => $organization->getKey(), 'user_id' => $user->getKey()],
            [
                'role' => $invitation->role,
                'status' => OrganizationMembership::STATUS_ACTIVE,
                'joined_at' => now(),
                'invited_by_user_id' => $invitation->invited_by_user_id,
                'suspended_at' => null,
            ],
        );

        $firstCompanyId = null;
        foreach ($assignments as $assignment) {
            CompanyMembership::query()->updateOrCreate(
                ['company_id' => $assignment->company_id, 'user_id' => $user->getKey()],
                [
                    'organization_id' => $organization->getKey(),
                    'role_id' => $assignment->role_id,
                    'custom_role_id' => $assignment->custom_role_id,
                    'status' => CompanyMembership::STATUS_ACTIVE,
                    'joined_at' => now(),
                    'invited_by_user_id' => $invitation->invited_by_user_id,
                    'suspended_at' => null,
                ],
            );
            $firstCompanyId ??= $assignment->company_id;
        }

        if ($firstCompanyId !== null && $user->default_company_id === null) {
            $legacyCompanyId = $user->company_id ?? $firstCompanyId;
            $user->forceFill([
                'company_id' => $legacyCompanyId,
                'default_company_id' => $firstCompanyId,
            ])->saveQuietly();
        }

        if ($firstCompanyId !== null && $user->roles()->count() === 0) {
            $firstAssignment = $assignments->firstWhere('company_id', $firstCompanyId);
            $role = $firstAssignment?->role;
            if ($role && $role->name !== UserRole::SuperAdmin->value) {
                $user->assignRole($role);
            }
        }

        $this->quotas->reconcile($organization);

        return $membership->refresh()->load(['organization', 'user']);
    }

    private function findByToken(string $token): OrganizationInvitation
    {
        return OrganizationInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->firstOrFail();
    }

    private function expireIfNeeded(OrganizationInvitation $invitation): void
    {
        if ($invitation->status === OrganizationInvitation::STATUS_PENDING && $invitation->expires_at->isPast()) {
            $expired = OrganizationInvitation::query()
                ->whereKey($invitation->getKey())
                ->where('status', OrganizationInvitation::STATUS_PENDING)
                ->where('expires_at', '<=', now())
                ->update([
                    'status' => OrganizationInvitation::STATUS_EXPIRED,
                    'updated_at' => now(),
                ]);

            $invitation->refresh();

            if ($expired !== 1) {
                return;
            }

            $this->quotas->reconcile($invitation->organization()->firstOrFail());
        }
    }

    private function ensureUserIsNotActiveMember(?User $user, Organization $organization, string $field): void
    {
        if (! $user) {
            return;
        }

        $alreadyActive = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', OrganizationMembership::STATUS_ACTIVE)
            ->exists();

        if ($alreadyActive) {
            throw ValidationException::withMessages([
                $field => ['This user is already an active organization member.'],
            ]);
        }
    }

    private function ensureInvitationBelongsTo(Organization $organization, OrganizationInvitation $invitation): void
    {
        abort_unless((int) $invitation->organization_id === (int) $organization->getKey(), 404);
    }
}
