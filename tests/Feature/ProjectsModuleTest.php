<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectsModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_task_activity_and_reporting_flow_works(): void
    {
        $admin = $this->admin();
        $project = $this->postJson('/api/projects', [
            'name' => 'ERP rollout',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'budget' => 50000,
            'status' => 'active',
        ])->assertCreated()->json('data');

        $task = $this->postJson("/api/projects/{$project['id']}/tasks", [
            'assignee_id' => $admin->id,
            'title' => 'Configure finance',
            'estimated_hours' => 8,
        ])->assertCreated()->json('data');

        $this->postJson("/api/project-tasks/{$task['id']}/time-logs", [
            'work_date' => '2026-08-06',
            'hours' => 2.5,
            'notes' => 'Configured chart of accounts',
        ])->assertCreated()->assertJsonPath('data.hours', 2.5);
        $this->postJson("/api/project-tasks/{$task['id']}/comments", ['body' => 'Ready for review'])
            ->assertCreated();
        $this->postJson("/api/projects/{$project['id']}/expenses", [
            'task_id' => $task['id'],
            'description' => 'Consulting',
            'amount' => 1200,
            'expense_date' => '2026-08-06',
        ])->assertCreated();

        $this->getJson("/api/projects/{$project['id']}/report")
            ->assertOk()
            ->assertJsonPath('data.actual_hours', 2.5)
            ->assertJsonPath('data.budget_spent', 1200)
            ->assertJsonPath('data.budget.spent', 1200);
    }

    public function test_a_project_owner_must_belong_to_the_selected_company(): void
    {
        $this->admin();
        $other = Company::factory()->create();
        $foreignOwner = User::factory()->create(['company_id' => $other->id]);

        $this->postJson('/api/projects', [
            'owner_id' => $foreignOwner->id,
            'name' => 'Cross-company project',
        ])->assertForbidden();
    }

    private function admin(): User
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin);

        return $admin;
    }
}
