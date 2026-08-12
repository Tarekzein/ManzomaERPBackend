<?php

namespace Tests\Feature;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\CompanyMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationInvitation;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Services\OrganizationAccessService;
use App\Modules\Organizations\Services\OrganizationInvitationService;
use App\Modules\Subscriptions\Exceptions\OrganizationQuotaExceededException;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use App\Modules\Subscriptions\Services\OrganizationEntitlementService;
use App\Modules\Subscriptions\Services\OrganizationQuotaService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrganizationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_company_creation_stops_at_the_organization_subscription_limit(): void
    {
        [$organization, , $owner] = $this->organizationFixture(maxCompanies: 2);
        Sanctum::actingAs($owner);

        $this->postJson("/api/organizations/{$organization->id}/companies", [
            'name' => 'Second Company',
            'timezone' => 'Africa/Cairo',
            'locale' => 'en',
            'currency' => 'EGP',
        ])->assertCreated()
            ->assertJsonPath('data.organization_id', $organization->id);

        $this->postJson("/api/organizations/{$organization->id}/companies", [
            'name' => 'Third Company',
            'timezone' => 'Africa/Cairo',
            'locale' => 'en',
            'currency' => 'EGP',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'COMPANY_LIMIT_REACHED')
            ->assertJsonPath('details.used', 2)
            ->assertJsonPath('details.limit', 2);

        $this->assertSame(2, $organization->companies()->whereNull('archived_at')->count());
    }

    public function test_invitation_reserves_a_seat_and_acceptance_converts_the_same_reservation(): void
    {
        [$organization, $company, $owner] = $this->organizationFixture(maxUsers: 2);
        Sanctum::actingAs($owner);
        $employeeRole = Role::findByName(UserRole::Employee->value, 'web');

        $invite = $this->postJson("/api/organizations/{$organization->id}/invitations", [
            'email' => 'invitee@example.test',
            'role' => OrganizationMembership::ROLE_MEMBER,
            'companies' => [[
                'company_id' => $company->id,
                'role_id' => $employeeRole->id,
            ]],
        ])->assertCreated();

        $token = $invite->json('data.token');
        $usage = app(OrganizationQuotaService::class)->usage($organization);
        $this->assertSame(1, $usage['users']['used']);
        $this->assertSame(1, $usage['users']['reserved']);
        $this->assertSame(0, $usage['users']['remaining']);

        $this->postJson("/api/organizations/{$organization->id}/invitations", [
            'email' => 'another@example.test',
            'role' => OrganizationMembership::ROLE_MEMBER,
        ])->assertUnprocessable()->assertJsonPath('code', 'USER_LIMIT_REACHED');

        $invitee = User::factory()->create([
            'company_id' => null,
            'default_company_id' => null,
            'email' => 'invitee@example.test',
            'is_active' => true,
        ]);
        Sanctum::actingAs($invitee);

        $this->postJson("/api/organization-invitations/{$token}/accept")
            ->assertOk()
            ->assertJsonPath('data.organization_id', $organization->id)
            ->assertJsonPath('data.status', OrganizationMembership::STATUS_ACTIVE);

        $this->assertDatabaseHas('company_memberships', [
            'company_id' => $company->id,
            'user_id' => $invitee->id,
            'status' => CompanyMembership::STATUS_ACTIVE,
        ]);
        $usage = app(OrganizationQuotaService::class)->usage($organization);
        $this->assertSame(2, $usage['users']['used']);
        $this->assertSame(0, $usage['users']['reserved']);

        // Reactivation is another seat-consuming path and must not bypass the
        // same organization lock or limit.
        $suspendedUser = User::factory()->create(['company_id' => null, 'is_active' => true]);
        $suspended = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $suspendedUser->id,
            'role' => OrganizationMembership::ROLE_MEMBER,
            'status' => OrganizationMembership::STATUS_SUSPENDED,
            'suspended_at' => now(),
        ]);
        Sanctum::actingAs($owner);
        $this->patchJson("/api/organizations/{$organization->id}/members/{$suspended->id}", [
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ])->assertUnprocessable()->assertJsonPath('code', 'USER_LIMIT_REACHED');

        // An expired invitation is not reserved; resending it must reserve a
        // seat again and therefore fail while the organization is full.
        $expired = OrganizationInvitation::query()->create([
            'organization_id' => $organization->id,
            'email' => 'expired@example.test',
            'token_hash' => hash('sha256', Str::random(64)),
            'role' => OrganizationMembership::ROLE_MEMBER,
            'status' => OrganizationInvitation::STATUS_PENDING,
            'invited_by_user_id' => $owner->id,
            'expires_at' => now()->subMinute(),
        ]);
        $this->postJson("/api/organizations/{$organization->id}/invitations/{$expired->id}/resend")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'USER_LIMIT_REACHED');
    }

    public function test_new_invitee_registers_and_accepts_without_creating_a_tenant_or_payment(): void
    {
        [$organization, $company, $owner] = $this->organizationFixture(maxUsers: 2);
        $employeeRole = Role::findByName(UserRole::Employee->value, 'web');
        $invite = app(OrganizationInvitationService::class)->invite($owner, $organization, [
            'email' => 'new.invitee@example.test',
            'role' => OrganizationMembership::ROLE_MEMBER,
            'companies' => [[
                'company_id' => $company->id,
                'role_id' => $employeeRole->id,
            ]],
        ]);
        $token = $invite['token'];
        $countsBefore = [
            'organizations' => Organization::query()->count(),
            'companies' => Company::query()->count(),
            'subscriptions' => CompanySubscription::query()->count(),
            'payments' => SubscriptionPayment::query()->count(),
        ];

        $this->getJson("/api/organization-invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('data.requires_registration', true);

        $response = $this->postJson("/api/organization-invitations/{$token}/register", [
            'name' => 'New Invitee',
            'email' => 'untrusted@example.test',
            'password' => 'Invitee#12345',
            'password_confirmation' => 'Invitee#12345',
            'device_name' => 'Organization invitation test',
        ])->assertCreated()
            ->assertJsonPath('data.membership.organization_id', $organization->id)
            ->assertJsonPath('data.membership.status', OrganizationMembership::STATUS_ACTIVE)
            ->assertJsonPath('data.auth.user.email', 'new.invitee@example.test')
            ->assertJsonPath('data.auth.user.default_company_id', $company->id)
            ->assertJsonPath('data.auth.user.workspaces.0.id', $company->id);

        $user = User::query()->where('email', 'new.invitee@example.test')->firstOrFail();
        $this->assertDatabaseMissing('users', ['email' => 'untrusted@example.test']);
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('company_memberships', [
            'company_id' => $company->id,
            'user_id' => $user->id,
            'status' => CompanyMembership::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('organization_invitations', [
            'id' => $invite['invitation']->id,
            'status' => OrganizationInvitation::STATUS_ACCEPTED,
            'accepted_by_user_id' => $user->id,
        ]);
        $this->assertSame($countsBefore['organizations'], Organization::query()->count());
        $this->assertSame($countsBefore['companies'], Company::query()->count());
        $this->assertSame($countsBefore['subscriptions'], CompanySubscription::query()->count());
        $this->assertSame($countsBefore['payments'], SubscriptionPayment::query()->count());

        $plainTextToken = $response->json('data.auth.token');
        $this->withToken($plainTextToken)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.workspaces.0.id', $company->id);

        $this->postJson("/api/organization-invitations/{$token}/register", [
            'name' => 'Replay',
            'password' => 'Invitee#12345',
            'password_confirmation' => 'Invitee#12345',
        ])->assertUnprocessable()->assertJsonValidationErrors('invitation');
        $this->assertSame(1, User::query()->where('email', 'new.invitee@example.test')->count());
    }

    public function test_existing_account_is_directed_to_sign_in_instead_of_being_recreated(): void
    {
        [$organization, $company, $owner] = $this->organizationFixture(maxUsers: 3);
        $existing = User::factory()->create([
            'company_id' => null,
            'default_company_id' => null,
            'email' => 'existing.invitee@example.test',
            'is_active' => true,
        ]);
        $employeeRole = Role::findByName(UserRole::Employee->value, 'web');
        $invite = app(OrganizationInvitationService::class)->invite($owner, $organization, [
            'email' => $existing->email,
            'role' => OrganizationMembership::ROLE_MEMBER,
            'companies' => [[
                'company_id' => $company->id,
                'role_id' => $employeeRole->id,
            ]],
        ]);

        $this->getJson("/api/organization-invitations/{$invite['token']}")
            ->assertOk()
            ->assertJsonPath('data.requires_registration', false);

        $this->postJson("/api/organization-invitations/{$invite['token']}/register", [
            'name' => 'Duplicate Account',
            'password' => 'Invitee#12345',
            'password_confirmation' => 'Invitee#12345',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertSame(OrganizationInvitation::STATUS_PENDING, $invite['invitation']->refresh()->status);
        $this->assertSame(1, User::query()->where('email', $existing->email)->count());
    }

    public function test_invitation_registration_rejects_an_archived_assigned_company_without_creating_a_user(): void
    {
        [$organization, $company, $owner] = $this->organizationFixture(maxUsers: 2);
        $employeeRole = Role::findByName(UserRole::Employee->value, 'web');
        $invite = app(OrganizationInvitationService::class)->invite($owner, $organization, [
            'email' => 'archived.workspace.invitee@example.test',
            'role' => OrganizationMembership::ROLE_MEMBER,
            'companies' => [[
                'company_id' => $company->id,
                'role_id' => $employeeRole->id,
            ]],
        ]);
        $company->forceFill(['is_active' => false, 'archived_at' => now()])->save();

        $this->postJson("/api/organization-invitations/{$invite['token']}/register", [
            'name' => 'Archived Workspace Invitee',
            'password' => 'Invitee#12345',
            'password_confirmation' => 'Invitee#12345',
        ])->assertUnprocessable()->assertJsonValidationErrors('invitation');

        $this->assertDatabaseMissing('users', ['email' => 'archived.workspace.invitee@example.test']);
        $this->assertSame(OrganizationInvitation::STATUS_PENDING, $invite['invitation']->refresh()->status);
    }

    public function test_invitation_registration_is_rate_limited_per_token(): void
    {
        [$organization, , $owner] = $this->organizationFixture(maxUsers: 2);
        $invite = app(OrganizationInvitationService::class)->invite($owner, $organization, [
            'email' => 'rate.limited.invitee@example.test',
            'role' => OrganizationMembership::ROLE_MEMBER,
            'companies' => [],
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson("/api/organization-invitations/{$invite['token']}/register", [
                'name' => 'Rate Limited Invitee',
                'password' => 'weak',
                'password_confirmation' => 'weak',
            ])->assertUnprocessable();
        }

        $this->postJson("/api/organization-invitations/{$invite['token']}/register", [
            'name' => 'Rate Limited Invitee',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertStatus(429);
    }

    public function test_nested_resources_and_organization_details_cannot_cross_organization_boundaries(): void
    {
        [$firstOrganization, , $firstOwner] = $this->organizationFixture();
        [$secondOrganization, $secondCompany] = $this->organizationFixture();
        Sanctum::actingAs($firstOwner);

        $this->getJson("/api/organizations/{$secondOrganization->id}")->assertForbidden();

        $this->postJson(
            "/api/organizations/{$firstOrganization->id}/companies/{$secondCompany->id}/archive"
        )->assertNotFound();

        $this->assertTrue($secondCompany->refresh()->is_active);
        $this->assertNull($secondCompany->archived_at);
    }

    public function test_managers_can_discover_archived_companies_for_restore(): void
    {
        [$organization, $company, $owner] = $this->organizationFixture();
        $archived = Company::factory()->create([
            'organization_id' => $organization->id,
            'is_active' => false,
            'archived_at' => now(),
        ]);
        Sanctum::actingAs($owner);

        $this->getJson("/api/organizations/{$organization->id}")
            ->assertOk()
            ->assertJsonPath('data.companies.0.id', $company->id)
            ->assertJsonPath('data.companies.1.id', $archived->id)
            ->assertJsonPath('data.companies.1.is_active', false)
            ->assertJsonPath('data.companies.1.archived_at', $archived->archived_at->toJSON());
    }

    public function test_billing_access_belongs_to_owner_and_billing_admin_not_organization_admin(): void
    {
        [$organization, , $owner] = $this->organizationFixture();
        $access = app(OrganizationAccessService::class);

        $admin = $this->organizationUser($organization, OrganizationMembership::ROLE_ADMIN);
        $billingAdmin = $this->organizationUser($organization, OrganizationMembership::ROLE_BILLING_ADMIN);

        $this->assertNotNull($access->ensureCanManageBilling($owner, $organization));
        $this->assertNotNull($access->ensureCanManageBilling($billingAdmin, $organization));

        $this->expectException(AuthorizationException::class);
        $access->ensureCanManageBilling($admin, $organization);
    }

    public function test_billing_admin_assignment_requires_a_company_workspace(): void
    {
        [$organization, , $owner] = $this->organizationFixture();
        Sanctum::actingAs($owner);

        $this->postJson("/api/organizations/{$organization->id}/invitations", [
            'email' => 'billing@example.test',
            'role' => OrganizationMembership::ROLE_BILLING_ADMIN,
        ])->assertUnprocessable()->assertJsonValidationErrors('companies');

        $member = $this->organizationUser($organization, OrganizationMembership::ROLE_MEMBER);
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $member->id)
            ->firstOrFail();

        $this->patchJson("/api/organizations/{$organization->id}/members/{$membership->id}", [
            'role' => OrganizationMembership::ROLE_BILLING_ADMIN,
        ])->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_resending_a_stale_expired_invitation_rechecks_capacity_under_lock(): void
    {
        [$organization, , $owner] = $this->organizationFixture(maxUsers: 1);
        $invitation = OrganizationInvitation::query()->create([
            'organization_id' => $organization->id,
            'email' => 'stale@example.test',
            'token_hash' => hash('sha256', Str::random(64)),
            'role' => OrganizationMembership::ROLE_MEMBER,
            'status' => OrganizationInvitation::STATUS_PENDING,
            'invited_by_user_id' => $owner->id,
            'expires_at' => now()->addDay(),
        ]);

        // Keep the passed model stale while the authoritative database row is
        // expired, reproducing the window that previously bypassed quota.
        OrganizationInvitation::query()->whereKey($invitation->id)->update(['expires_at' => now()->subMinute()]);

        try {
            app(OrganizationInvitationService::class)->resend($owner, $organization, $invitation);
            $this->fail('Resending should reserve a new seat and exceed the organization limit.');
        } catch (OrganizationQuotaExceededException $exception) {
            $this->assertSame('USER_LIMIT_REACHED', $exception->errorCode);
        }
    }

    /** @return array{Organization, Company, User, SubscriptionPlan, CompanySubscription} */
    private function organizationFixture(int $maxCompanies = 3, int $maxUsers = 10): array
    {
        $organization = Organization::query()->create([
            'name' => fake()->unique()->company().' Organization',
            'status' => Organization::STATUS_ACTIVE,
            'timezone' => 'Africa/Cairo',
            'locale' => 'en',
            'currency' => 'EGP',
        ]);
        $plan = SubscriptionPlan::query()->create([
            'slug' => 'plan-'.Str::lower(Str::random(10)),
            'name' => 'Organization Plan',
            'monthly_price' => 100,
            'annual_price' => 1000,
            'currency' => 'EGP',
            'max_companies' => $maxCompanies,
            'max_users' => $maxUsers,
            'storage_gb' => 50,
            'api_rate_limit_per_minute' => 120,
            'trial_enabled' => false,
            'trial_days' => 0,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $company = Company::factory()->create([
            'organization_id' => $organization->id,
            'name' => fake()->unique()->company(),
            'plan' => $plan->slug,
        ]);
        $owner = User::factory()->create([
            'company_id' => $company->id,
            'default_company_id' => $company->id,
            'is_active' => true,
        ]);
        $owner->assignRole(UserRole::CompanyAdmin->value);
        $organization->forceFill(['created_by_user_id' => $owner->id])->save();
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'role' => OrganizationMembership::ROLE_OWNER,
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
        CompanyMembership::query()->create([
            'organization_id' => $organization->id,
            'company_id' => $company->id,
            'user_id' => $owner->id,
            'role_id' => Role::findByName(UserRole::CompanyAdmin->value, 'web')->id,
            'status' => CompanyMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
        $subscription = CompanySubscription::query()->create([
            'company_id' => $company->id,
            'organization_id' => $organization->id,
            'subscription_plan_id' => $plan->id,
            'entitlements_snapshot' => app(OrganizationEntitlementService::class)->snapshot($plan),
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'auto_renew' => false,
            'starts_at' => now(),
            'current_period_started_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);

        return [$organization, $company, $owner, $plan, $subscription];
    }

    private function organizationUser(Organization $organization, string $role): User
    {
        $user = User::factory()->create(['company_id' => null, 'is_active' => true]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        return $user;
    }
}
