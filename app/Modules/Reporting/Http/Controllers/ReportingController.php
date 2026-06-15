<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Http\Requests\ReportingRequest;
use App\Modules\Reporting\Models\ReportDashboardWidget;
use App\Modules\Reporting\Models\ReportDefinition;
use App\Modules\Reporting\Models\ReportRun;
use App\Modules\Reporting\Models\ReportSchedule;
use App\Modules\Reporting\Policies\ReportingPolicy;
use App\Modules\Reporting\Services\ReportEngine;
use App\Modules\Reporting\Services\ReportingService;
use App\Modules\Reporting\Services\ScheduledReportService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ReportingController extends Controller
{
    public function __construct(
        private readonly ReportingService $reporting,
        private readonly ReportEngine $engine,
        private readonly ReportingPolicy $policy,
        private readonly ScheduledReportService $scheduledReports,
    ) {}

    public function catalog(Request $request)
    {
        $this->policy->companyId($request->user(), requestedCompanyId: $request->integer('company_id') ?: null);

        return ApiResponse::success(['sources' => $this->engine->sources(), 'prebuilt' => $this->engine->prebuilt()]);
    }

    public function dashboard(Request $request)
    {
        return ApiResponse::success($this->reporting->dashboard($request->user(), $request));
    }

    public function preview(ReportingRequest $request)
    {
        $companyId = $this->policy->companyId($request->user(), 'reporting.view', $request->integer('company_id') ?: null);

        return ApiResponse::success($this->engine->execute($companyId, $request->validated()));
    }

    public function runPrebuilt(Request $request, string $key)
    {
        $companyId = $this->policy->companyId($request->user(), 'reporting.view', $request->integer('company_id') ?: null);
        $definition = collect($this->engine->prebuilt())->firstWhere('key', $key);
        abort_unless($definition, 404, 'Pre-built report not found.');

        return ApiResponse::success($this->engine->execute($companyId, $definition));
    }

    public function reports(Request $request)
    {
        return ApiResponse::success($this->reporting->list($request->user(), ReportDefinition::class, $request));
    }

    public function storeReport(ReportingRequest $request)
    {
        return ApiResponse::success($this->reporting->saveReport($request->user(), $request->validated()), 'Report created', status: 201);
    }

    public function updateReport(ReportingRequest $request, ReportDefinition $report)
    {
        return ApiResponse::success($this->reporting->saveReport($request->user(), $request->validated(), $report), 'Report updated');
    }

    public function deleteReport(Request $request, ReportDefinition $report)
    {
        $this->reporting->delete($request->user(), $report);

        return ApiResponse::success(null, 'Report deleted');
    }

    public function runReport(Request $request, ReportDefinition $report)
    {
        return ApiResponse::success($this->reporting->run($request->user(), $report));
    }

    public function export(Request $request, ReportDefinition $report, string $format)
    {
        abort_unless(in_array($format, ['csv', 'xlsx', 'pdf'], true), 404);
        abort_unless($request->user()->can('reporting.export'), 403);
        $result = $this->reporting->run($request->user(), $report, $format);
        [$bytes, $mime] = $this->scheduledReports->export($format, $report->name, $result);

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="'.str($report->name)->slug().".{$format}\"",
        ]);
    }

    public function widgets(Request $request)
    {
        return ApiResponse::success($this->reporting->list($request->user(), ReportDashboardWidget::class, $request));
    }

    public function storeWidget(ReportingRequest $request)
    {
        return ApiResponse::success($this->reporting->saveWidget($request->user(), $request->validated()), 'Widget created', status: 201);
    }

    public function updateWidget(ReportingRequest $request, ReportDashboardWidget $widget)
    {
        return ApiResponse::success($this->reporting->saveWidget($request->user(), $request->validated(), $widget), 'Widget updated');
    }

    public function deleteWidget(Request $request, ReportDashboardWidget $widget)
    {
        $this->reporting->delete($request->user(), $widget);

        return ApiResponse::success(null, 'Widget deleted');
    }

    public function reorderWidgets(ReportingRequest $request)
    {
        $this->reporting->reorderWidgets($request->user(), $request->validated('widgets'));

        return ApiResponse::success(null, 'Widgets reordered');
    }

    public function schedules(Request $request)
    {
        $companyId = $this->policy->companyId($request->user(), requestedCompanyId: $request->integer('company_id') ?: null);

        return ApiResponse::success(ReportSchedule::with('report')->where('company_id', $companyId)->latest()->get());
    }

    public function storeSchedule(ReportingRequest $request)
    {
        return ApiResponse::success($this->reporting->saveSchedule($request->user(), $request->validated()), 'Schedule created', status: 201);
    }

    public function updateSchedule(ReportingRequest $request, ReportSchedule $schedule)
    {
        return ApiResponse::success($this->reporting->saveSchedule($request->user(), $request->validated(), $schedule), 'Schedule updated');
    }

    public function deleteSchedule(Request $request, ReportSchedule $schedule)
    {
        $this->reporting->delete($request->user(), $schedule);

        return ApiResponse::success(null, 'Schedule deleted');
    }

    public function runs(Request $request)
    {
        return ApiResponse::success($this->reporting->list($request->user(), ReportRun::class, $request));
    }
}
