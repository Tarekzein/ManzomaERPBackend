<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Reporting\Events\ReportDataUpdated;
use App\Modules\Reporting\Models\ReportAlert;
use App\Modules\Reporting\Models\ReportDashboardWidget;
use App\Modules\Reporting\Models\ReportDefinition;
use App\Modules\Reporting\Models\ReportRun;
use App\Modules\Reporting\Models\ReportSchedule;
use App\Modules\Reporting\Policies\ReportingPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ReportingService
{
    public function __construct(private readonly ReportingPolicy $policy, private readonly ReportEngine $engine) {}

    public function list(User $user, string $model, Request $request)
    {
        $companyId = $this->policy->companyId($user, 'reporting.view', $request->integer('company_id') ?: null);

        $query = $model::query()->where('company_id', $companyId)->latest();

        if ($model === ReportDefinition::class) {
            $query->leftJoin('report_favorites as fav', function ($join) use ($user) {
                $join->on('fav.report_definition_id', '=', 'report_definitions.id')
                    ->where('fav.user_id', '=', $user->id);
            })
            ->select('report_definitions.*', DB::raw('CASE WHEN fav.user_id IS NOT NULL THEN 1 ELSE 0 END as is_favorited'))
            ->orderByDesc('is_favorited')
            ->orderByDesc('report_definitions.created_at');
        }

        return $query->get();
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
        $this->evaluateAlerts($companyId, $report, $result);
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

    public function toggleFavorite(\App\Modules\Authentication\Models\User $user, ReportDefinition $report): array
    {
        $this->policy->ensureOwned($user, $report, 'reporting.view');
        $exists = DB::table('report_favorites')
            ->where('user_id', $user->id)
            ->where('report_definition_id', $report->id)
            ->exists();

        if ($exists) {
            DB::table('report_favorites')
                ->where('user_id', $user->id)
                ->where('report_definition_id', $report->id)
                ->delete();

            return ['favorited' => false];
        }

        DB::table('report_favorites')->insert([
            'user_id' => $user->id,
            'report_definition_id' => $report->id,
            'created_at' => now(),
        ]);

        return ['favorited' => true];
    }

    public function toggleShare(\App\Modules\Authentication\Models\User $user, ReportDefinition $report): ReportDefinition
    {
        $this->policy->ensureOwned($user, $report, 'reporting.edit');
        $report->update([
            'share_token' => $report->share_token ? null : Str::uuid()->toString(),
        ]);

        return $report->fresh();
    }

    public function runByToken(string $token): array
    {
        $report = ReportDefinition::where('share_token', $token)->firstOrFail();
        $result = $this->engine->executeDefinition((int) $report->company_id, $report);

        return [
            'report' => $report->only(['name', 'description', 'chart_type', 'source']),
            'result' => $result,
        ];
    }

    public function saveAlert(\App\Modules\Authentication\Models\User $user, array $data, ?ReportAlert $alert = null): ReportAlert
    {
        $companyId = $alert
            ? $this->policy->ensureOwned($user, $alert)
            : $this->policy->companyId($user, 'reporting.create', $data['company_id'] ?? null);

        ReportDefinition::where('company_id', $companyId)->findOrFail($data['report_definition_id']);
        unset($data['company_id']);

        $record = $alert ?? new ReportAlert(['company_id' => $companyId, 'created_by' => $user->id]);
        $record->fill($data)->save();

        return $record->fresh()->load('report');
    }

    public function listAlerts(\App\Modules\Authentication\Models\User $user, \Illuminate\Http\Request $request): \Illuminate\Support\Collection
    {
        $companyId = $this->policy->companyId($user, 'reporting.view', $request->integer('company_id') ?: null);

        return ReportAlert::with('report')->where('company_id', $companyId)->latest()->get();
    }

    public function deleteAlert(\App\Modules\Authentication\Models\User $user, ReportAlert $alert): void
    {
        $this->policy->ensureOwned($user, $alert, 'reporting.delete');
        $alert->delete();
    }

    public function evaluateAlerts(int $companyId, ReportDefinition $report, array $result): void
    {
        $alerts = ReportAlert::where('report_definition_id', $report->id)->where('is_active', true)->get();
        if ($alerts->isEmpty()) {
            return;
        }

        foreach ($alerts as $alert) {
            $values = array_column($result['rows'] ?? [], $alert->metric_field);
            $aggregate = array_sum($values);

            if ($this->evaluateCondition($aggregate, $alert->operator, $alert->threshold_value)) {
                $alert->update(['last_triggered_at' => now()]);
                $this->sendAlertEmail($alert, $aggregate, $report);
            }
        }
    }

    private function evaluateCondition(float $actual, string $operator, float $threshold): bool
    {
        return match ($operator) {
            '>' => $actual > $threshold,
            '>=' => $actual >= $threshold,
            '<' => $actual < $threshold,
            '<=' => $actual <= $threshold,
            '=' => abs($actual - $threshold) < 0.0001,
            '!=' => abs($actual - $threshold) >= 0.0001,
            default => false,
        };
    }

    private function sendAlertEmail(ReportAlert $alert, float $value, ReportDefinition $report): void
    {
        $data = [
            'alert_name' => $alert->name,
            'report_name' => $report->name,
            'metric_field' => $alert->metric_field,
            'operator' => $alert->operator,
            'threshold' => $alert->threshold_value,
            'actual_value' => $value,
        ];

        try {
            Mail::send(
                'reporting::email',
                ['type' => 'alert'] + $data,
                function ($message) use ($alert, $data) {
                    $message->to($alert->recipients)
                        ->subject("Report Alert: {$data['alert_name']} triggered");
                }
            );
        } catch (\Throwable) {
            // Silently fail — alert email is non-critical
        }
    }
}
