<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\Reporting\Models\ReportSchedule;
use App\Modules\Reporting\Services\ScheduledReportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportingModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_custom_reports_widgets_exports_and_schedules_work(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/reporting/catalog')
            ->assertOk()
            ->assertJsonStructure(['data' => ['sources' => ['sales_orders'], 'prebuilt']]);

        $this->getJson('/api/reporting/prebuilt/sales-revenue')
            ->assertOk()
            ->assertJsonPath('data.source', 'sales_orders');

        $definition = [
            'name' => 'Sales status summary',
            'source' => 'sales_orders',
            'fields' => ['status'],
            'filters' => [],
            'groupings' => ['status'],
            'metrics' => [['field' => 'total', 'aggregate' => 'sum'], ['field' => 'id', 'aggregate' => 'count']],
            'chart_type' => 'bar',
        ];
        $report = $this->postJson('/api/reporting/reports', $definition)->assertCreated()->json('data');
        $this->postJson("/api/reporting/reports/{$report['id']}/run")->assertOk()->assertJsonPath('data.source', 'sales_orders');
        $this->get("/api/reporting/reports/{$report['id']}/export/csv")->assertOk()->assertHeader('content-type', 'text/csv; charset=utf-8');
        $this->get("/api/reporting/reports/{$report['id']}/export/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get("/api/reporting/reports/{$report['id']}/export/xlsx")->assertOk();

        $widget = $this->postJson('/api/reporting/widgets', [
            'title' => 'Sales by status',
            'source' => 'sales_orders',
            'chart_type' => 'bar',
            'configuration' => ['fields' => ['status'], 'groupings' => ['status'], 'metrics' => [['field' => 'total', 'aggregate' => 'sum']]],
            'position' => 10,
            'width' => 2,
        ])->assertCreated()->json('data');
        $this->postJson('/api/reporting/widgets/reorder', ['widgets' => [['id' => $widget['id'], 'position' => 0]]])->assertOk();
        $this->getJson('/api/reporting/dashboard')->assertOk()->assertJsonStructure(['data' => ['widgets', 'live']]);

        $this->postJson('/api/reporting/schedules', [
            'report_definition_id' => $report['id'],
            'name' => 'Daily sales status',
            'frequency' => 'daily',
            'format' => 'csv',
            'recipients' => ['finance@example.com'],
        ])->assertCreated();
        $this->getJson('/api/reporting/runs')->assertOk();
    }

    public function test_scheduled_reports_are_emailed_and_unsafe_fields_are_rejected(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson('/api/reporting/preview', [
            'name' => 'Unsafe',
            'source' => 'sales_orders',
            'fields' => ['password'],
            'chart_type' => 'table',
        ])->assertUnprocessable();

        Mail::fake();
        ReportSchedule::query()->update(['next_run_at' => now()->subMinute(), 'format' => 'csv']);
        $this->assertGreaterThan(0, app(ScheduledReportService::class)->runDue());
        Mail::assertSentCount(1);
        $this->assertDatabaseHas('report_runs', ['status' => 'completed', 'format' => 'csv']);
    }

    public function test_company_users_cannot_access_another_company_report(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        $other = \App\Modules\Companies\Models\Company::factory()->create();
        $report = \App\Modules\Reporting\Models\ReportDefinition::create([
            'company_id' => $other->id, 'name' => 'Other report', 'source' => 'projects',
            'fields' => ['status'], 'filters' => [], 'groupings' => ['status'],
            'metrics' => [['field' => 'id', 'aggregate' => 'count']], 'chart_type' => 'bar',
        ]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/reporting/reports/{$report->id}/run")->assertForbidden();
    }
}
