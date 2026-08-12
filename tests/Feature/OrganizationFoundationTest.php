<?php

namespace Tests\Feature;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\CompanyMembership;
use App\Modules\Organizations\Models\CompanyMembershipPermissionOverride;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationInvitation;
use App\Modules\Organizations\Models\OrganizationInvitationCompany;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Platform\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrganizationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_models_represent_memberships_invitations_and_company_access(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Nile Group',
            'status' => Organization::STATUS_ACTIVE,
            'timezone' => 'Africa/Cairo',
            'locale' => 'en',
            'currency' => 'EGP',
        ]);
        $company = Company::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create([
            'company_id' => $company->id,
            'default_company_id' => $company->id,
        ]);
        $organizationMembership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => OrganizationMembership::ROLE_OWNER,
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
        $companyMembership = CompanyMembership::query()->create([
            'organization_id' => $organization->id,
            'company_id' => $company->id,
            'user_id' => $user->id,
            'status' => CompanyMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
        $override = $companyMembership->permissionOverrides()->create([
            'permission_name' => 'inventory.export',
            'effect' => CompanyMembershipPermissionOverride::EFFECT_DENY,
        ]);
        $invitation = OrganizationInvitation::query()->create([
            'organization_id' => $organization->id,
            'email' => 'member@example.test',
            'token_hash' => hash('sha256', 'one-time-token'),
            'role' => OrganizationMembership::ROLE_MEMBER,
            'status' => OrganizationInvitation::STATUS_PENDING,
            'invited_by_user_id' => $user->id,
            'expires_at' => now()->addWeek(),
        ]);
        $invitationCompany = OrganizationInvitationCompany::query()->create([
            'organization_id' => $organization->id,
            'organization_invitation_id' => $invitation->id,
            'company_id' => $company->id,
        ]);

        $this->assertTrue($organization->companies()->whereKey($company->id)->exists());
        $this->assertTrue($organization->members()->whereKey($user->id)->exists());
        $this->assertTrue($user->organizations()->whereKey($organization->id)->exists());
        $this->assertTrue($user->companies()->whereKey($company->id)->exists());
        $this->assertTrue($company->organization->is($organization));
        $this->assertTrue($user->defaultCompany->is($company));
        $this->assertTrue($organizationMembership->user->is($user));
        $this->assertTrue($override->companyMembership->is($companyMembership));
        $this->assertTrue($invitationCompany->invitation->is($invitation));
        $this->assertArrayNotHasKey('token_hash', $invitation->toArray());
    }

    public function test_backfill_is_idempotent_and_copies_legacy_access_data(): void
    {
        $companyAdminRole = Role::findOrCreate(UserRole::CompanyAdmin->value, 'web');
        $company = Company::factory()->create();
        $admin = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $admin->assignRole($companyAdminRole);
        $override = $admin->permissionOverrides()->create([
            'permission_name' => 'finance.export',
            'effect' => 'deny',
        ]);
        AuditLog::query()->create([
            'company_id' => $company->id,
            'user_id' => $admin->id,
            'event' => 'legacy.created',
            'created_at' => now(),
        ]);

        $this->artisan('organizations:backfill', ['--fail-on-issues' => true])
            ->assertExitCode(0);
        $this->artisan('organizations:backfill', ['--fail-on-issues' => true])
            ->assertExitCode(0);

        $company->refresh();
        $admin->refresh();
        $organization = $company->organization;
        $membership = CompanyMembership::query()
            ->where('company_id', $company->id)
            ->where('user_id', $admin->id)
            ->sole();

        $this->assertNotNull($organization);
        $this->assertSame($company->id, $admin->default_company_id);
        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('organization_memberships', 1);
        $this->assertDatabaseCount('company_memberships', 1);
        $this->assertDatabaseCount('company_membership_permission_overrides', 1);
        $this->assertSame($companyAdminRole->id, $membership->role_id);
        $this->assertNull($membership->custom_role_id);
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'role' => OrganizationMembership::ROLE_OWNER,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('company_membership_permission_overrides', [
            'company_membership_id' => $membership->id,
            'permission_name' => $override->permission_name,
            'effect' => $override->effect,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $company->id,
            'organization_id' => $organization->id,
        ]);
    }

    public function test_database_rejects_a_membership_that_crosses_organization_boundaries(): void
    {
        $firstOrganization = Organization::query()->create(['name' => 'First Group']);
        $secondOrganization = Organization::query()->create(['name' => 'Second Group']);
        $firstCompany = Company::factory()->create(['organization_id' => $firstOrganization->id]);
        $secondCompany = Company::factory()->create(['organization_id' => $secondOrganization->id]);
        $user = User::factory()->create([
            'company_id' => $firstCompany->id,
            'default_company_id' => $firstCompany->id,
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $firstOrganization->id,
            'user_id' => $user->id,
            'role' => OrganizationMembership::ROLE_MEMBER,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $this->expectException(QueryException::class);

        CompanyMembership::query()->create([
            'organization_id' => $firstOrganization->id,
            'company_id' => $secondCompany->id,
            'user_id' => $user->id,
            'status' => CompanyMembership::STATUS_ACTIVE,
        ]);
    }
}
