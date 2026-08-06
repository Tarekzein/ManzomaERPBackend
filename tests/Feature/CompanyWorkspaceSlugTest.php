<?php

namespace Tests\Feature;

use App\Modules\Companies\Models\Company;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyWorkspaceSlugTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_company_gets_a_url_safe_workspace_slug(): void
    {
        $company = Company::factory()->create(['name' => 'Acme Trading & Co.']);

        $this->assertSame('acme-trading-co', $company->slug);
        $this->assertSame('acme-trading-co', $company->workspaceKey());
    }

    public function test_companies_with_the_same_name_get_distinct_slugs(): void
    {
        $first = Company::factory()->create(['name' => 'Nile Foods']);
        $second = Company::factory()->create(['name' => 'Nile Foods']);
        $third = Company::factory()->create(['name' => 'Nile Foods']);

        $this->assertSame(['nile-foods', 'nile-foods-2', 'nile-foods-3'], [$first->slug, $second->slug, $third->slug]);
    }

    public function test_registration_exposes_the_workspace_slug_to_the_client(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionSeeder::class);

        $this->postJson('/api/auth/register', [
            'company_name' => 'Delta Logistics',
            'name' => 'Delta Admin',
            'email' => 'delta@example.com',
            'password' => 'Secret#123',
            'password_confirmation' => 'Secret#123',
            'device_name' => 'phpunit',
            'plan_slug' => 'basic',
            'billing_cycle' => 'monthly',
        ])->assertCreated()->assertJsonPath('data.company.slug', 'delta-logistics');
    }
}
