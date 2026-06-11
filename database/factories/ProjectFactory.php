<?php

namespace Database\Factories;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('-2 months', '+1 month');
        $endDate = fake()->dateTimeBetween($startDate, '+8 months');

        return [
            'company_id' => Company::query()->inRandomOrder()->value('id') ?? Company::factory(),
            'owner_id' => function (array $attributes): int {
                return User::query()
                    ->where('company_id', $attributes['company_id'])
                    ->inRandomOrder()
                    ->value('id')
                    ?? User::factory()->create(['company_id' => $attributes['company_id']])->id;
            },
            'name' => fake()->unique()->randomElement([
                'ERP Implementation',
                'Warehouse Optimization',
                'Finance Automation',
                'Customer Portal Rollout',
                'Mobile Sales Enablement',
                'Procurement Workflow Upgrade',
            ]).' '.fake()->bothify('##'),
            'description' => fake()->paragraph(),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'budget' => fake()->randomFloat(2, 50000, 750000),
            'status' => fake()->randomElement(ProjectStatus::values()),
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => [
            'company_id' => $company->id,
        ]);
    }

    public function ownedBy(User $owner): static
    {
        return $this->state(fn () => [
            'company_id' => $owner->company_id,
            'owner_id' => $owner->id,
        ]);
    }
}
