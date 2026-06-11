<?php

namespace Database\Factories;

use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectExpense;
use App\Modules\Projects\Models\ProjectTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectExpense>
 */
class ProjectExpenseFactory extends Factory
{
    protected $model = ProjectExpense::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::query()->inRandomOrder()->value('id') ?? Project::factory(),
            'task_id' => null,
            'finance_reference' => 'FIN-'.fake()->unique()->numerify('######'),
            'category' => fake()->randomElement(['software', 'hardware', 'consulting', 'travel', 'training']),
            'description' => fake()->sentence(),
            'amount' => fake()->randomFloat(2, 500, 50000),
            'expense_date' => fake()->dateTimeBetween('-2 months', 'now'),
        ];
    }

    public function forTask(ProjectTask $task): static
    {
        return $this->state(fn () => [
            'project_id' => $task->project_id,
            'task_id' => $task->id,
        ]);
    }
}
