<?php

namespace Database\Factories;

use App\Modules\Authentication\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectTask;
use App\Modules\Projects\Models\ProjectTimeLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectTimeLog>
 */
class ProjectTimeLogFactory extends Factory
{
    protected $model = ProjectTimeLog::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::query()->inRandomOrder()->value('id') ?? Project::factory(),
            'task_id' => null,
            'user_id' => function (array $attributes): int {
                $companyId = Project::query()->whereKey($attributes['project_id'])->value('company_id');

                return User::query()
                    ->where('company_id', $companyId)
                    ->inRandomOrder()
                    ->value('id')
                    ?? User::factory()->create(['company_id' => $companyId])->id;
            },
            'work_date' => fake()->dateTimeBetween('-6 weeks', 'now'),
            'hours' => fake()->randomFloat(2, 1, 8),
            'notes' => fake()->sentence(),
        ];
    }

    public function forTask(ProjectTask $task): static
    {
        return $this->state(fn () => [
            'project_id' => $task->project_id,
            'task_id' => $task->id,
        ]);
    }

    public function loggedBy(User $user): static
    {
        return $this->state(fn () => [
            'user_id' => $user->id,
        ]);
    }
}
