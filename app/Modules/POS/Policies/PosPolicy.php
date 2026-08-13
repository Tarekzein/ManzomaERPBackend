<?php

namespace App\Modules\POS\Policies;

use App\Modules\Authentication\Models\User;
use App\Modules\POS\Models\PosRegister;
use App\Modules\POS\Models\PosRegisterAssignment;
use App\Modules\POS\Models\PosShift;
use App\Modules\Platform\Services\CompanyContext;
use App\Modules\Platform\Services\EffectiveAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Authorization for the till.
 *
 * Two things are checked everywhere, and neither is optional:
 *
 *  - the company comes from CompanyContext, never from `users.company_id`, so
 *    a workspace switch or a crafted id cannot reach another tenant's sales;
 *  - selling requires an *assignment* to the register as well as the
 *    permission, because "may use a POS" and "may use this till" are different
 *    questions.
 */
class PosPolicy
{
    public function __construct(
        private readonly EffectiveAccessService $access,
        private readonly CompanyContext $context,
    ) {}

    /** The workspace every POS query must be scoped to. */
    public function companyId(User $user, ?string $permission = null): int
    {
        $companyId = $this->context->companyIdFor($user);

        if (! $companyId) {
            throw new AuthorizationException('Select a company workspace before using the point of sale.');
        }

        if ($permission !== null) {
            $this->ensure($user, $permission);
        }

        return $companyId;
    }

    /** The trading name printed on a receipt. */
    public function companyName(User $user): string
    {
        $company = $this->context->companyFor($user);

        return (string) (data_get($company?->settings, 'display_name') ?: $company?->name ?: '');
    }

    /** The calendar date on which this workspace is trading. */
    public function businessDate(User $user): string
    {
        $timezone = $this->context->companyFor($user)?->timezone ?: config('app.timezone');

        return now($timezone)->toDateString();
    }

    public function ensure(User $user, string $permission): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }

        if (! $this->access->can($user, $permission, 'pos')) {
            throw new AuthorizationException('You are not allowed to perform this point of sale action.');
        }
    }

    public function can(User $user, string $permission): bool
    {
        return $user->isSuperAdmin() || $this->access->can($user, $permission, 'pos');
    }

    /** A register from this workspace, or a 404 — never another tenant's. */
    public function register(User $user, int $registerId): PosRegister
    {
        return PosRegister::query()
            ->where('company_id', $this->companyId($user))
            ->findOrFail($registerId);
    }

    /**
     * Confirm the user may actually staff this register.
     *
     * Super admins and holders of pos.registers.manage bypass the roster,
     * because they configure it; everyone else needs a current assignment.
     */
    public function ensureAssigned(User $user, PosRegister $register, string $role = PosRegisterAssignment::ROLE_CASHIER): void
    {
        if ($user->isSuperAdmin() || $this->can($user, 'pos.registers.manage')) {
            return;
        }

        $businessDate = $this->businessDate($user);
        $assignment = PosRegisterAssignment::query()
            ->where('pos_register_id', $register->getKey())
            ->where('user_id', $user->getKey())
            ->when(
                $role === PosRegisterAssignment::ROLE_SUPERVISOR,
                fn ($query) => $query->where('role', PosRegisterAssignment::ROLE_SUPERVISOR),
            )
            ->get()
            ->first(fn (PosRegisterAssignment $candidate) => $candidate->isCurrent($businessDate));

        if (! $assignment) {
            throw new AuthorizationException('You are not assigned to this register.');
        }
    }

    public function ensureRegisterIsUsable(PosRegister $register): void
    {
        if (! $register->is_active) {
            throw ValidationException::withMessages([
                'register' => ['This register is disabled.'],
            ]);
        }
    }

    /** The caller's open shift on this register, or a clear refusal. */
    public function openShift(User $user, PosRegister $register): PosShift
    {
        $shift = PosShift::query()
            ->where('company_id', $register->company_id)
            ->where('pos_register_id', $register->getKey())
            ->where('cashier_id', $user->getKey())
            ->where('status', PosShift::STATUS_OPEN)
            ->first();

        if (! $shift) {
            throw ValidationException::withMessages([
                'shift' => ['Open a shift on this register before selling.'],
            ]);
        }

        return $shift;
    }

    /**
     * Resolve the authenticated approver for a supervisor action.
     *
     * A user id in a request is not proof that the named user approved it. Until
     * a separate signed approval challenge exists, only the authenticated actor
     * may approve an override and appear in its audit trail.
     */
    public function resolveSupervisor(User $actor, ?int $supervisorId): ?User
    {
        if ($supervisorId !== null && $supervisorId !== (int) $actor->getKey()) {
            throw new AuthorizationException('A supervisor must authenticate before approving this action.');
        }

        $this->ensure($actor, 'pos.supervisor_override');

        return $actor;
    }

    /** Manual terminal approval always requires a current supervisor roster entry. */
    public function ensureSupervisorAssigned(User $user, PosRegister $register): void
    {
        $businessDate = $this->businessDate($user);
        $assignment = PosRegisterAssignment::query()
            ->where('company_id', $register->company_id)
            ->where('pos_register_id', $register->getKey())
            ->where('user_id', $user->getKey())
            ->where('role', PosRegisterAssignment::ROLE_SUPERVISOR)
            ->get()
            ->first(fn (PosRegisterAssignment $candidate) => $candidate->isCurrent($businessDate));

        if (! $assignment) {
            throw new AuthorizationException('You are not assigned to this register as a supervisor.');
        }
    }
}
