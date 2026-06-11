<?php

namespace Database\Factories;

use App\Modules\Companies\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'plan' => fake()->randomElement(['starter', 'professional', 'enterprise']),
            'timezone' => 'Africa/Cairo',
            'locale' => 'en',
            'currency' => 'EGP',
            'is_active' => true,
            'settings' => [],
        ];
    }
}
