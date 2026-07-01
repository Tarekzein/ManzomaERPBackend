<?php

namespace App\Modules\Reporting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportingRequest extends FormRequest
{
    public function rules(): array
    {
        $reportRules = [
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'source' => ['required', 'string', 'max:80'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['string'],
            'filters' => ['nullable', 'array'],
            'filters.*.field' => ['required', 'string'],
            'filters.*.operator' => ['required', Rule::in(['=', '!=', '>', '>=', '<', '<=', 'contains', 'in', 'between', 'is_null', 'not_null', 'starts_with', 'ends_with'])],
            'filters.*.value' => ['nullable'],
            'groupings' => ['nullable', 'array'],
            'groupings.*' => ['string'],
            'metrics' => ['nullable', 'array'],
            'metrics.*.field' => ['required', 'string'],
            'metrics.*.aggregate' => ['required', Rule::in(['count', 'sum', 'avg', 'min', 'max'])],
            'chart_type' => ['nullable', Rule::in(['table', 'bar', 'line', 'pie', 'area', 'number'])],
            'is_shared' => ['nullable', 'boolean'],
        ];

        return match ($this->route()?->getName()) {
            'reporting.reports.store', 'reporting.reports.update', 'reporting.preview' => $reportRules,
            'reporting.widgets.store', 'reporting.widgets.update' => [
                'company_id' => ['nullable', 'integer', 'exists:companies,id'],
                'report_definition_id' => ['nullable', 'integer', 'exists:report_definitions,id'],
                'title' => ['required', 'string', 'max:150'],
                'source' => ['required', 'string', 'max:80'],
                'chart_type' => ['required', Rule::in(['bar', 'line', 'pie', 'area', 'number', 'table'])],
                'configuration' => ['required', 'array'],
                'position' => ['nullable', 'integer', 'min:0'],
                'width' => ['nullable', 'integer', 'min:1', 'max:3'],
            ],
            'reporting.widgets.reorder' => [
                'widgets' => ['required', 'array'],
                'widgets.*.id' => ['required', 'integer', 'exists:report_dashboard_widgets,id'],
                'widgets.*.position' => ['required', 'integer', 'min:0'],
            ],
            'reporting.schedules.store', 'reporting.schedules.update' => [
                'company_id' => ['nullable', 'integer', 'exists:companies,id'],
                'report_definition_id' => ['required', 'integer', 'exists:report_definitions,id'],
                'name' => ['required', 'string', 'max:150'],
                'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
                'format' => ['required', Rule::in(['csv', 'xlsx', 'pdf'])],
                'recipients' => ['required', 'array', 'min:1'],
                'recipients.*' => ['email'],
                'is_active' => ['nullable', 'boolean'],
                'next_run_at' => ['nullable', 'date'],
            ],
            'reporting.reports.run-comparison' => [
                'compare_to' => ['required', Rule::in(['previous_period', 'previous_year'])],
            ],
            'reporting.alerts.store', 'reporting.alerts.update' => [
                'company_id' => ['nullable', 'integer', 'exists:companies,id'],
                'report_definition_id' => ['required', 'integer', 'exists:report_definitions,id'],
                'name' => ['required', 'string', 'max:150'],
                'metric_field' => ['required', 'string', 'max:100'],
                'operator' => ['required', Rule::in(['>', '>=', '<', '<=', '=', '!='])],
                'threshold_value' => ['required', 'numeric'],
                'recipients' => ['required', 'array', 'min:1'],
                'recipients.*' => ['email'],
                'is_active' => ['nullable', 'boolean'],
            ],
            default => [],
        };
    }
}
