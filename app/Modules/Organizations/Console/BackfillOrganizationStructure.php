<?php

namespace App\Modules\Organizations\Console;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\CompanyMembership;
use App\Modules\Organizations\Models\CompanyMembershipPermissionOverride;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Throwable;

class BackfillOrganizationStructure extends Command
{
    private int $chunkSize = 100;

    protected $signature = 'organizations:backfill
        {--chunk=100 : Number of companies to read per chunk}
        {--reconcile-only : Report consistency issues without writing}
        {--fail-on-issues : Return a non-zero exit code when reconciliation finds issues}';

    protected $description = 'Idempotently project legacy single-company tenants into organizations and reconcile the result';

    public function handle(): int
    {
        if (! Schema::hasTable('organizations') || ! Schema::hasTable('company_memberships')) {
            $this->error('The organization foundation migrations have not been run.');

            return self::FAILURE;
        }

        if (! $this->option('reconcile-only')) {
            $this->chunkSize = max(1, (int) $this->option('chunk'));
            $processed = 0;

            Company::query()
                ->select('id')
                ->orderBy('id')
                ->chunkById($this->chunkSize, function (EloquentCollection $companies) use (&$processed): void {
                    foreach ($companies as $company) {
                        $this->backfillCompany((int) $company->getKey());
                        $processed++;
                    }
                });

            $this->info("Backfilled {$processed} companies.");
        }

        $issues = $this->reconciliationIssues();

        $this->table(
            ['Check', 'Issues'],
            collect($issues)->map(fn (int $count, string $check) => [$check, $count])->values()->all()
        );

        $totalIssues = array_sum($issues);

        if ($totalIssues === 0) {
            $this->info('Organization reconciliation passed.');

            return self::SUCCESS;
        }

        $this->warn("Organization reconciliation found {$totalIssues} issue(s).");

        return $this->option('fail-on-issues') ? self::FAILURE : self::SUCCESS;
    }

    private function backfillCompany(int $companyId): void
    {
        DB::transaction(function () use ($companyId): void {
            $company = Company::query()->lockForUpdate()->findOrFail($companyId);
            $users = User::query()
                ->where('company_id', $company->getKey())
                ->with(['roles:id,name', 'customRole:id,company_id', 'permissionOverrides'])
                ->orderBy('id')
                ->get();
            $owner = $this->legacyOwner($users);

            $organization = $company->organization_id
                ? Organization::query()->findOrFail($company->organization_id)
                : Organization::query()->create([
                    'slug' => Organization::uniqueSlug($company->workspaceKey()),
                    'name' => $company->name,
                    'status' => Organization::STATUS_ACTIVE,
                    'billing_email' => $owner?->email,
                    'timezone' => $company->timezone,
                    'locale' => $company->locale,
                    'currency' => $company->currency,
                    'settings' => ['migration' => ['legacy_company_id' => $company->getKey()]],
                    'created_by_user_id' => $owner?->getKey(),
                ]);

            if ((int) $company->organization_id !== (int) $organization->getKey()) {
                $company->forceFill(['organization_id' => $organization->getKey()])->save();
            }

            $this->translateLegacyBillingSuspension($company, $organization);

            foreach ($users as $user) {
                $organizationRole = $user->is($owner)
                    ? OrganizationMembership::ROLE_OWNER
                    : ($user->hasRole(UserRole::CompanyAdmin->value)
                        ? OrganizationMembership::ROLE_ADMIN
                        : OrganizationMembership::ROLE_MEMBER);
                $status = $user->is_active === false
                    ? OrganizationMembership::STATUS_SUSPENDED
                    : OrganizationMembership::STATUS_ACTIVE;

                $organizationMembership = OrganizationMembership::query()->firstOrCreate(
                    [
                        'organization_id' => $organization->getKey(),
                        'user_id' => $user->getKey(),
                    ],
                    [
                        'role' => $organizationRole,
                        'status' => $status,
                        'joined_at' => $user->created_at ?? now(),
                        'suspended_at' => $status === OrganizationMembership::STATUS_SUSPENDED
                            ? ($user->deactivated_at ?? now())
                            : null,
                    ]
                );

                if ($organizationMembership->joined_at === null) {
                    $organizationMembership->forceFill([
                        'joined_at' => $user->created_at ?? now(),
                    ])->save();
                }

                $customRoleId = (int) $user->customRole?->company_id === (int) $company->getKey()
                    ? $user->custom_role_id
                    : null;
                $fixedRoleId = $customRoleId ? null : $this->legacyCompanyRole($user)?->getKey();
                $membership = CompanyMembership::query()->firstOrCreate(
                    [
                        'company_id' => $company->getKey(),
                        'user_id' => $user->getKey(),
                    ],
                    [
                        'organization_id' => $organization->getKey(),
                        'role_id' => $fixedRoleId,
                        'custom_role_id' => $customRoleId,
                        'status' => $status,
                        'joined_at' => $user->created_at ?? now(),
                        'suspended_at' => $status === CompanyMembership::STATUS_SUSPENDED
                            ? ($user->deactivated_at ?? now())
                            : null,
                    ]
                );

                if ($membership->joined_at === null) {
                    $membership->forceFill([
                        'joined_at' => $user->created_at ?? now(),
                    ])->save();
                }

                foreach ($user->permissionOverrides as $override) {
                    CompanyMembershipPermissionOverride::query()->firstOrCreate(
                        [
                            'company_membership_id' => $membership->getKey(),
                            'permission_name' => $override->permission_name,
                        ],
                        ['effect' => $override->effect]
                    );
                }

                if ($user->default_company_id === null || ! CompanyMembership::query()
                    ->where('company_id', $user->default_company_id)
                    ->where('user_id', $user->getKey())
                    ->exists()) {
                    $user->forceFill(['default_company_id' => $company->getKey()])->save();
                }
            }

            $this->ensureActiveOwner($organization, $owner);

            DB::table('audit_logs')
                ->where('company_id', $company->getKey())
                ->whereNull('organization_id')
                ->update(['organization_id' => $organization->getKey()]);

            $this->backfillBillingReferences($company->getKey(), $organization->getKey());
        }, 3);
    }

    private function translateLegacyBillingSuspension(Company $company, Organization $organization): void
    {
        $settings = (array) ($company->settings ?? []);
        $legacySuspendedAt = data_get($settings, 'billing.suspended_at');

        if ($company->is_active || blank($legacySuspendedAt)) {
            return;
        }

        try {
            if (! is_scalar($legacySuspendedAt)) {
                throw new \InvalidArgumentException('Legacy billing suspension timestamp is not scalar.');
            }

            $suspendedAt = Carbon::parse((string) $legacySuspendedAt);
            $usedFallbackTimestamp = false;
        } catch (Throwable) {
            $suspendedAt = $company->updated_at ?? now();
            $usedFallbackTimestamp = true;
        }

        $translatedAt = now();
        $migrationRecord = [
            'legacy_suspended_at' => $legacySuspendedAt,
            'translated_at' => $translatedAt->toISOString(),
            'previous_is_active' => false,
            'timestamp_fallback_used' => $usedFallbackTimestamp,
        ];
        $organizationSettings = (array) ($organization->settings ?? []);
        $organizationMigration = is_array($organizationSettings['migration'] ?? null)
            ? $organizationSettings['migration']
            : [];
        $legacySuspensions = is_array($organizationMigration['legacy_billing_suspensions'] ?? null)
            ? $organizationMigration['legacy_billing_suspensions']
            : [];
        $legacySuspensions[(string) $company->getKey()] = $migrationRecord;
        $organizationMigration['legacy_billing_suspensions'] = $legacySuspensions;
        $organizationSettings['migration'] = $organizationMigration;

        $organization->forceFill([
            'billing_suspended_at' => $organization->billing_suspended_at ?? $suspendedAt,
            'settings' => $organizationSettings,
        ])->save();

        $companyMigration = is_array($settings['migration'] ?? null) ? $settings['migration'] : [];
        $companyMigration['legacy_billing_suspension'] = $migrationRecord;
        $settings['migration'] = $companyMigration;

        if (is_array($settings['billing'] ?? null)) {
            unset($settings['billing']['suspended_at']);
        }

        if (($settings['billing'] ?? null) === []) {
            unset($settings['billing']);
        }

        // Billing suspension is now organization state. Reactivating this flag
        // prevents a later successful payment from remaining blocked by the old
        // company-level representation. Administrative suspensions have no
        // billing marker and are deliberately left untouched.
        $company->forceFill([
            'is_active' => true,
            'settings' => $settings,
        ])->save();
    }

    private function ensureActiveOwner(Organization $organization, ?User $legacyOwner): void
    {
        if (! $legacyOwner || $legacyOwner->is_active === false) {
            return;
        }

        $hasActiveOwner = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('role', OrganizationMembership::ROLE_OWNER)
            ->where('status', OrganizationMembership::STATUS_ACTIVE)
            ->exists();

        if ($hasActiveOwner) {
            return;
        }

        // Promote an already-active legacy member when only the role projection
        // is incomplete. Never reactivate a suspended/removed membership here;
        // that requires an explicit security decision by an administrator.
        OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $legacyOwner->getKey())
            ->where('status', OrganizationMembership::STATUS_ACTIVE)
            ->update([
                'role' => OrganizationMembership::ROLE_OWNER,
                'suspended_at' => null,
                'updated_at' => now(),
            ]);
    }

    /** @param EloquentCollection<int, User> $users */
    private function legacyOwner(EloquentCollection $users): ?User
    {
        return $users->first(
            fn (User $user) => $user->is_active !== false && $user->hasRole(UserRole::CompanyAdmin->value)
        ) ?? $users->first(fn (User $user) => $user->is_active !== false)
            ?? $users->first();
    }

    private function legacyCompanyRole(User $user): ?Role
    {
        $priority = [
            UserRole::CompanyAdmin->value,
            UserRole::Manager->value,
            UserRole::Employee->value,
        ];

        foreach ($priority as $roleName) {
            $role = $user->roles->firstWhere('name', $roleName);

            if ($role instanceof Role) {
                return $role;
            }
        }

        return $user->roles->first(fn (Role $role) => $role->name !== UserRole::SuperAdmin->value);
    }

    private function backfillBillingReferences(int $companyId, int $organizationId): void
    {
        if (Schema::hasTable('company_subscriptions')
            && Schema::hasColumn('company_subscriptions', 'organization_id')) {
            DB::table('company_subscriptions')
                ->where('company_id', $companyId)
                ->whereNull('organization_id')
                ->update(['organization_id' => $organizationId]);

            $this->backfillEntitlementSnapshots($companyId);
        }

        if (! Schema::hasTable('subscription_payments')
            || ! Schema::hasColumn('subscription_payments', 'organization_id')) {
            return;
        }

        DB::table('subscription_payments')
            ->where('company_id', $companyId)
            ->whereNull('organization_id')
            ->update(['organization_id' => $organizationId]);

        if (Schema::hasColumn('subscription_payments', 'initiated_from_company_id')) {
            DB::table('subscription_payments')
                ->where('company_id', $companyId)
                ->whereNull('initiated_from_company_id')
                ->update(['initiated_from_company_id' => $companyId]);
        }
    }

    private function backfillEntitlementSnapshots(int $companyId): void
    {
        if (! Schema::hasColumn('company_subscriptions', 'entitlements_snapshot')
            || ! Schema::hasColumn('subscription_plans', 'max_companies')) {
            return;
        }

        DB::table('company_subscriptions as subscriptions')
            ->join('subscription_plans as plans', 'plans.id', '=', 'subscriptions.subscription_plan_id')
            ->where('subscriptions.company_id', $companyId)
            ->whereNull('subscriptions.entitlements_snapshot')
            ->select([
                'subscriptions.id as subscription_id',
                'plans.max_companies',
                'plans.max_users',
                'plans.storage_gb',
                'plans.api_rate_limit_per_minute',
            ])
            ->chunkById($this->chunkSize, function ($subscriptions): void {
                foreach ($subscriptions as $subscription) {
                    DB::table('company_subscriptions')
                        ->where('id', $subscription->subscription_id)
                        ->update([
                            'entitlements_snapshot' => json_encode([
                                'max_companies' => $subscription->max_companies === null
                                    ? null
                                    : (int) $subscription->max_companies,
                                'max_users' => $subscription->max_users === null
                                    ? null
                                    : (int) $subscription->max_users,
                                'storage_gb' => $subscription->storage_gb === null
                                    ? null
                                    : (int) $subscription->storage_gb,
                                'api_rate_limit_per_minute' => $subscription->api_rate_limit_per_minute === null
                                    ? null
                                    : (int) $subscription->api_rate_limit_per_minute,
                            ], JSON_THROW_ON_ERROR),
                        ]);
                }
            }, 'subscriptions.id', 'subscription_id');
    }

    /** @return array<string, int> */
    private function reconciliationIssues(): array
    {
        $issues = [
            'companies_without_organization' => Company::query()->whereNull('organization_id')->count(),
            'legacy_users_without_company_membership' => DB::table('users as users')
                ->leftJoin('company_memberships as memberships', function ($join) {
                    $join->on('memberships.user_id', '=', 'users.id')
                        ->on('memberships.company_id', '=', 'users.company_id');
                })
                ->whereNotNull('users.company_id')
                ->whereNull('memberships.id')
                ->count(),
            'legacy_users_without_organization_membership' => DB::table('users as users')
                ->join('companies as companies', 'companies.id', '=', 'users.company_id')
                ->leftJoin('organization_memberships as memberships', function ($join) {
                    $join->on('memberships.user_id', '=', 'users.id')
                        ->on('memberships.organization_id', '=', 'companies.organization_id');
                })
                ->whereNotNull('users.company_id')
                ->whereNull('memberships.id')
                ->count(),
            'company_membership_organization_mismatches' => DB::table('company_memberships as memberships')
                ->join('companies as companies', 'companies.id', '=', 'memberships.company_id')
                ->whereColumn('memberships.organization_id', '!=', 'companies.organization_id')
                ->count(),
            'custom_role_company_mismatches' => DB::table('company_memberships as memberships')
                ->join('company_custom_roles as roles', 'roles.id', '=', 'memberships.custom_role_id')
                ->whereColumn('memberships.company_id', '!=', 'roles.company_id')
                ->count(),
            'users_without_valid_default_company' => DB::table('users as users')
                ->leftJoin('company_memberships as memberships', function ($join) {
                    $join->on('memberships.user_id', '=', 'users.id')
                        ->on('memberships.company_id', '=', 'users.default_company_id');
                })
                ->whereNotNull('users.company_id')
                ->where(function ($query) {
                    $query->whereNull('users.default_company_id')->orWhereNull('memberships.id');
                })
                ->count(),
            'organizations_without_active_owner' => Organization::query()
                ->whereDoesntHave('memberships', fn ($query) => $query
                    ->where('role', OrganizationMembership::ROLE_OWNER)
                    ->where('status', OrganizationMembership::STATUS_ACTIVE))
                ->count(),
            'company_audit_logs_without_organization' => DB::table('audit_logs')
                ->whereNotNull('company_id')
                ->whereNull('organization_id')
                ->count(),
        ];

        foreach (['company_subscriptions', 'subscription_payments'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'organization_id')) {
                $issues["{$table}_without_organization"] = DB::table($table)
                    ->whereNotNull('company_id')
                    ->whereNull('organization_id')
                    ->count();
                $issues["{$table}_organization_mismatches"] = DB::table("{$table} as billing_records")
                    ->join('companies as companies', 'companies.id', '=', 'billing_records.company_id')
                    ->whereNotNull('billing_records.organization_id')
                    ->whereColumn('billing_records.organization_id', '!=', 'companies.organization_id')
                    ->count();
            }
        }

        if (Schema::hasTable('company_subscriptions')
            && Schema::hasColumn('company_subscriptions', 'entitlements_snapshot')) {
            $issues['company_subscriptions_without_entitlements_snapshot'] = DB::table('company_subscriptions')
                ->whereNotNull('company_id')
                ->whereNull('entitlements_snapshot')
                ->count();
        }

        if (Schema::hasTable('subscription_payments')
            && Schema::hasColumn('subscription_payments', 'initiated_from_company_id')) {
            $issues['subscription_payments_without_initiating_company'] = DB::table('subscription_payments')
                ->whereNotNull('company_id')
                ->whereNull('initiated_from_company_id')
                ->count();
        }

        $issues['legacy_billing_suspensions_not_translated'] = Company::query()
            ->whereNotNull('organization_id')
            ->where('is_active', false)
            ->whereNotNull('settings->billing->suspended_at')
            ->count();

        if (Schema::hasTable('company_subscriptions')
            && Schema::hasColumn('company_subscriptions', 'organization_id')) {
            $issues['organizations_with_multiple_serving_subscriptions'] = DB::query()
                ->fromSub(
                    DB::table('company_subscriptions')
                        ->select('organization_id')
                        ->whereNotNull('organization_id')
                        ->whereIn('status', ['active', 'trialing', 'past_due'])
                        ->groupBy('organization_id')
                        ->havingRaw('COUNT(*) > 1'),
                    'duplicates'
                )
                ->count();
        }

        return $issues;
    }
}
