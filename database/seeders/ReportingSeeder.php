<?php

namespace Database\Seeders;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Reporting\Models\ReportDashboardWidget;
use App\Modules\Reporting\Models\ReportDefinition;
use App\Modules\Reporting\Models\ReportSchedule;
use Illuminate\Database\Seeder;

class ReportingSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->each(function (Company $company) {
            $user = User::where('company_id', $company->id)->oldest()->first();
            if (! $user) {
                return;
            }

            $report = ReportDefinition::updateOrCreate(
                ['company_id' => $company->id, 'name' => 'Monthly sales performance'],
                [
                    'created_by' => $user->id,
                    'description' => 'Sales order totals grouped by status.',
                    'source' => 'sales_orders',
                    'fields' => ['status'],
                    'filters' => [],
                    'groupings' => ['status'],
                    'metrics' => [['field' => 'total', 'aggregate' => 'sum'], ['field' => 'id', 'aggregate' => 'count']],
                    'chart_type' => 'bar',
                    'is_shared' => true,
                ]
            );

            $widgets = [
                ['title' => 'Sales revenue by status', 'source' => 'sales_orders', 'chart_type' => 'bar', 'configuration' => ['fields' => ['status'], 'groupings' => ['status'], 'metrics' => [['field' => 'total', 'aggregate' => 'sum']]], 'position' => 0, 'width' => 2],
                ['title' => 'Open CRM pipeline', 'source' => 'crm_opportunities', 'chart_type' => 'number', 'configuration' => ['fields' => ['status'], 'filters' => [['field' => 'status', 'operator' => '=', 'value' => 'open']], 'metrics' => [['field' => 'value', 'aggregate' => 'sum']]], 'position' => 1, 'width' => 1],
                ['title' => 'Project portfolio', 'source' => 'projects', 'chart_type' => 'pie', 'configuration' => ['fields' => ['status'], 'groupings' => ['status'], 'metrics' => [['field' => 'id', 'aggregate' => 'count']]], 'position' => 2, 'width' => 1],
                ['title' => 'Headcount', 'source' => 'hr_employees', 'chart_type' => 'number', 'configuration' => ['fields' => ['status'], 'filters' => [['field' => 'status', 'operator' => '=', 'value' => 'active']], 'metrics' => [['field' => 'id', 'aggregate' => 'count']]], 'position' => 3, 'width' => 1],
            ];
            foreach ($widgets as $widget) {
                ReportDashboardWidget::updateOrCreate(
                    ['company_id' => $company->id, 'user_id' => $user->id, 'title' => $widget['title']],
                    $widget + ['report_definition_id' => $widget['source'] === 'sales_orders' ? $report->id : null]
                );
            }

            ReportSchedule::updateOrCreate(
                ['company_id' => $company->id, 'name' => 'Weekly sales performance'],
                [
                    'report_definition_id' => $report->id,
                    'created_by' => $user->id,
                    'frequency' => 'weekly',
                    'format' => 'xlsx',
                    'recipients' => [$user->email],
                    'is_active' => true,
                    'next_run_at' => now()->addWeek(),
                ]
            );
        });
    }
}
