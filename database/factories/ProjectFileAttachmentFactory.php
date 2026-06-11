<?php

namespace Database\Factories;

use App\Modules\Authentication\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectFileAttachment;
use App\Modules\Projects\Models\ProjectTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectFileAttachment>
 */
class ProjectFileAttachmentFactory extends Factory
{
    protected $model = ProjectFileAttachment::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::query()->inRandomOrder()->value('id') ?? Project::factory(),
            'task_id' => null,
            'uploaded_by' => function (array $attributes): int {
                $companyId = Project::query()->whereKey($attributes['project_id'])->value('company_id');

                return User::query()
                    ->where('company_id', $companyId)
                    ->inRandomOrder()
                    ->value('id')
                    ?? User::factory()->create(['company_id' => $companyId])->id;
            },
            'disk' => 'local',
            'path' => 'projects/'.fake()->uuid().'/'.fake()->slug().'.pdf',
            'original_name' => fake()->randomElement(['proposal.pdf', 'timeline.pdf', 'requirements.docx', 'handover.xlsx']),
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(50_000, 5_000_000),
            'comment' => fake()->optional()->sentence(),
        ];
    }

    public function forTask(ProjectTask $task): static
    {
        return $this->state(fn () => [
            'project_id' => $task->project_id,
            'task_id' => $task->id,
        ]);
    }

    public function uploadedBy(User $user): static
    {
        return $this->state(fn () => [
            'uploaded_by' => $user->id,
        ]);
    }
}
