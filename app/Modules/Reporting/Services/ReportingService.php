<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Reporting\Events\ReportDataUpdated;
use App\Modules\Reporting\Models\ReportDashboardWidget;
use App\Modules\Reporting\Models\ReportDefinition;
use App\Modules\Reporting\Models\ReportRun;
use App\Modules\Reporting\Models\ReportSchedule;
use App\Modules\Reporting\Policies\ReportingPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ReportingService
{
    public function __construct(private readonly ReportingPolicy $policy, private readonly ReportEngine $engine) {}

    public function list(User $user, string $model, Request $request)
    {
        $companyId = $this->policy->companyId($user, 'reporting.view', $request->integer('company_id') ?: null);

        return $model::query()->where('company_id', $companyId)->latest()->get();
    }

    public function saveReport(User $user, array $data, ?ReportDefinition $report = null): ReportDefinition
    {
        $companyId = $report
            ? $this->policy->ensureOwned($user, $report)
            : $this->policy->companyId($user, 'reporting.create', $data['company_id'] ?? null);
        $this->engine->execute($companyId, $data);
        $report ??= new ReportDefinition;
        $report->fill($data + ['company_id' => $companyId, 'created_by' => $user->id])->save();
        event(new ReportDataUpdated($companyId, $report->source));

        return $report->fresh();
    }

    public function run(User $user, ReportDefinition $report, string $format = 'json'): array
    {
        $companyId = $this->policy->ensureOwned($user, $report, 'reporting.view');
        $result = $this->engine->executeDefinition($companyId, $report);
        ReportRun::create([
            'company_id' => $companyId, 'report_definition_id' => $report->id, 'requested_by' => $user->id,
            'status' => 'completed', 'format' => $format, 'row_count' => $result['row_count'], 'completed_at' => now(),
        ]);

        return $result;
    }

    public function dashboard(User $user, Request $request): array
    {
        $companyId = $this->policy->companyId($user, 'reporting.view', $request->integer('company_id') ?: null);
        $widgets = ReportDashboardWidget::where('company_id', $companyId)->where('user_id', $user->id)->orderBy('position')->get();

        return [
            'widgets' => $widgets->map(function (ReportDashboardWidget $widget) use ($companyId) {
                $definition = $widget->configuration + ['source' => $widget->source, 'chart_type' => $widget->chart_type];
                return $widget->toArray() + ['result' => $this->engine->execute($companyId, $definition)];
            })->values(),
            'live' => ['channel' => "private-companies.{$companyId}.reporting", 'event' => 'report.data.updated'],
        ];
    }

    public function saveWidget(User $user, array $data, ?ReportDashboardWidget $widget = null): ReportDashboardWidget
    {
        $companyId = $widget
            ? $this->policy->ensureOwned($user, $widget)
            : $this->policy->companyId($user, 'reporting.create', $data['company_id'] ?? null);
        $this->engine->execute($companyId, $data['configuration'] + ['source' => $data['source'], 'chart_type' => $data['chart_type']]);
        $widget ??= new ReportDashboardWidget;
        $widget->fill($data + ['company_id' => $companyId, 'user_id' => $user->id])->save();

        return $widget->fresh();
    }

    public function reorderWidgets(User $user, array $widgets): void
    {
        foreach ($widgets as $item) {
            $widget = ReportDashboardWidget::findOrFail($item['id']);
            $this->policy->ensureOwned($user, $widget);
            $widget->update(['position' => $item['position']]);
        }
    }

    public function saveSchedule(User $user, array $data, ?ReportSchedule $schedule = null): ReportSchedule
    {
        $companyId = $schedule
            ? $this->policy->ensureOwned($user, $schedule)
            : $this->policy->companyId($user, 'reporting.create', $data['company_id'] ?? null);
        $report = ReportDefinition::where('company_id', $companyId)->findOrFail($data['report_definition_id']);
        $schedule ??= new ReportSchedule;
        $schedule->fill($data + ['company_id' => $companyId, 'created_by' => $user->id, 'next_run_at' => now()])->save();

        return $schedule->fresh('report');
    }

    public function delete(User $user, Model $model): void
    {
        $this->policy->ensureOwned($user, $model, 'reporting.delete');
        $model->delete();
    }
}
