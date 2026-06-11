<?php

namespace Database\Factories;

use App\Modules\Authentication\Models\User;
use App\Modules\Projects\Enums\TaskPriority;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectTask>
 */
class ProjectTaskFactory extends Factory
{
    protected $model = ProjectTask::class;

    public function definition(): array
    {
        $status = fake()->randomElement(TaskStatus::values());
        $startDate = $status === TaskStatus::Done->value
            ? fake()->dateTimeBetween('-2 months', '-1 week')
            : fake()->dateTimeBetween('-1 month', '+2 months');

        return [
            'project_id' => Project::query()->inRandomOrder()->value('id') ?? Project::factory(),
            'assignee_id' => function (array $attributes): ?int {
                $companyId = Project::query()->whereKey($attributes['project_id'])->value('company_id');

                return User::query()
                    ->where('company_id', $companyId)
                    ->inRandomOrder()
                    ->value('id');
            },
            'title' => fake()->randomElement([
                'Gather requirements',
                'Configure workflows',
                'Prepare migration plan',
                'Review access rules',
                'Build reporting dashboard',
                'Run acceptance testing',
                'Train key users',
            ]),
            'description' => fake()->sentence(16),
            'priority' => fake()->randomElement(TaskPriority::values()),
            'status' => $status,
            'estimated_hours' => fake()->randomFloat(2, 4, 80),
            'sort_order' => fake()->numberBetween(1, 100),
            'start_date' => $startDate,
            'due_date' => fake()->dateTimeBetween($startDate, '+4 months'),
            'completed_at' => $status === TaskStatus::Done->value ? fake()->dateTimeBetween($startDate, 'now') : null,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => [
            'project_id' => $project->id,
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn () => [
            'assignee_id' => $user->id,
        ]);
    }
}
