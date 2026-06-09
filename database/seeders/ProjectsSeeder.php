<?php

namespace Database\Seeders;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectComment;
use App\Modules\Projects\Models\ProjectExpense;
use App\Modules\Projects\Models\ProjectFileAttachment;
use App\Modules\Projects\Models\ProjectTask;
use App\Modules\Projects\Models\ProjectTimeLog;
use Illuminate\Database\Seeder;

class ProjectsSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()
            ->where('is_active', true)
            ->each(fn (Company $company) => $this->seedCompanyProjects($company));
    }

    private function seedCompanyProjects(Company $company): void
    {
        if (Project::query()->where('company_id', $company->id)->exists()) {
            return;
        }

        $users = $this->ensureCompanyUsers($company);
        $owner = $users->first();

        Project::factory()
            ->count(3)
            ->forCompany($company)
            ->ownedBy($owner)
            ->sequence(
                ['name' => 'ERP Implementation', 'status' => ProjectStatus::Active->value],
                ['name' => 'Warehouse Optimization', 'status' => ProjectStatus::OnHold->value],
                ['name' => 'Finance Automation', 'status' => ProjectStatus::Completed->value],
            )
            ->create()
            ->each(function (Project $project) use ($users): void {
                $tasks = ProjectTask::factory()
                    ->count(5)
                    ->forProject($project)
                    ->sequence(
                        ['status' => TaskStatus::Done->value, 'sort_order' => 1],
                        ['status' => TaskStatus::InProgress->value, 'sort_order' => 2],
                        ['status' => TaskStatus::ToDo->value, 'sort_order' => 3],
                        ['status' => TaskStatus::ToDo->value, 'sort_order' => 4],
                        ['status' => TaskStatus::InProgress->value, 'sort_order' => 5],
                    )
                    ->create();

                $tasks->each(function (ProjectTask $task) use ($users): void {
                    $user = $users->random();

                    $task->forceFill(['assignee_id' => $user->id])->save();

                    ProjectTimeLog::factory()
                        ->count(2)
                        ->forTask($task)
                        ->loggedBy($user)
                        ->create();

                    ProjectComment::factory()
                        ->forTask($task)
                        ->authoredBy($user)
                        ->create();
                });

                ProjectFileAttachment::factory()
                    ->count(2)
                    ->forTask($tasks->random())
                    ->uploadedBy($users->random())
                    ->create();

                ProjectExpense::factory()
                    ->count(3)
                    ->forTask($tasks->random())
                    ->create();
            });
    }

    private function ensureCompanyUsers(Company $company)
    {
        $users = User::query()->where('company_id', $company->id)->get();

        if ($users->count() >= 3) {
            return $users;
        }

        User::factory()
            ->count(3 - $users->count())
            ->create(['company_id' => $company->id]);

        return User::query()->where('company_id', $company->id)->get();
    }
}
