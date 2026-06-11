<?php

namespace Database\Factories;

use App\Modules\Authentication\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectComment;
use App\Modules\Projects\Models\ProjectTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectComment>
 */
class ProjectCommentFactory extends Factory
{
    protected $model = ProjectComment::class;

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
            'body' => fake()->paragraph(),
        ];
    }

    public function forTask(ProjectTask $task): static
    {
        return $this->state(fn () => [
            'project_id' => $task->project_id,
            'task_id' => $task->id,
        ]);
    }

    public function authoredBy(User $user): static
    {
        return $this->state(fn () => [
            'user_id' => $user->id,
        ]);
    }
}
