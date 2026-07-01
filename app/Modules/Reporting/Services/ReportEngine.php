<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Models\ReportDefinition;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportEngine
{
    public function sources(): array
    {
        return [
            'finance_invoices' => $this->source('Finance invoices', 'invoices', 'invoice_date', [
                'id', 'type', 'number', 'invoice_date', 'due_date', 'currency', 'subtotal', 'tax_total', 'total', 'paid_total', 'status',
            ], ['type', 'status', 'currency', 'invoice_date'], ['id', 'subtotal', 'tax_total', 'total', 'paid_total']),
            'sales_orders' => $this->source('Sales orders', 'sales_orders', 'order_date', [
                'id', 'number', 'order_date', 'expected_ship_date', 'status', 'currency', 'subtotal', 'discount_total', 'tax_total', 'total',
            ], ['status', 'currency', 'order_date'], ['id', 'subtotal', 'discount_total', 'tax_total', 'total']),
            'inventory_stock' => $this->source('Inventory stock', 'stock_balances', 'created_at', [
                'id', 'product_id', 'warehouse_id', 'quantity', 'average_cost', 'reorder_point', 'reorder_quantity', 'created_at',
            ], ['product_id', 'warehouse_id'], ['id', 'quantity', 'average_cost', 'reorder_point', 'reorder_quantity']),
            'projects' => $this->source('Projects', 'projects', 'start_date', [
                'id', 'owner_id', 'name', 'start_date', 'end_date', 'budget', 'status', 'created_at',
            ], ['status', 'owner_id', 'start_date'], ['id', 'budget']),
            'hr_employees' => $this->source('HR employees', 'hr_employees', 'hire_date', [
                'id', 'department_id', 'team_id', 'employee_number', 'name', 'position', 'hire_date', 'termination_date', 'status', 'base_salary', 'currency',
            ], ['department_id', 'team_id', 'position', 'status', 'currency', 'hire_date'], ['id', 'base_salary']),
            'crm_opportunities' => $this->source('CRM opportunities', 'crm_opportunities', 'expected_close_date', [
                'id', 'contact_id', 'stage_id', 'owner_id', 'title', 'value', 'currency', 'expected_close_date', 'probability', 'status', 'created_at',
            ], ['stage_id', 'owner_id', 'status', 'currency', 'expected_close_date'], ['id', 'value', 'probability']),
        ];
    }

    public function prebuilt(): array
    {
        return [
            ['key' => 'finance-receivables', 'module' => 'Finance', 'name' => 'Receivables by status', 'source' => 'finance_invoices', 'fields' => ['status'], 'filters' => [['field' => 'type', 'operator' => '=', 'value' => 'receivable']], 'groupings' => ['status'], 'metrics' => [['field' => 'total', 'aggregate' => 'sum'], ['field' => 'id', 'aggregate' => 'count']], 'chart_type' => 'bar'],
            ['key' => 'sales-revenue', 'module' => 'Sales', 'name' => 'Sales revenue by status', 'source' => 'sales_orders', 'fields' => ['status'], 'filters' => [], 'groupings' => ['status'], 'metrics' => [['field' => 'total', 'aggregate' => 'sum'], ['field' => 'id', 'aggregate' => 'count']], 'chart_type' => 'bar'],
            ['key' => 'inventory-valuation', 'module' => 'Inventory', 'name' => 'Stock by warehouse', 'source' => 'inventory_stock', 'fields' => ['warehouse_id'], 'filters' => [], 'groupings' => ['warehouse_id'], 'metrics' => [['field' => 'quantity', 'aggregate' => 'sum'], ['field' => 'average_cost', 'aggregate' => 'avg']], 'chart_type' => 'bar'],
            ['key' => 'project-portfolio', 'module' => 'Projects', 'name' => 'Project portfolio', 'source' => 'projects', 'fields' => ['status'], 'filters' => [], 'groupings' => ['status'], 'metrics' => [['field' => 'budget', 'aggregate' => 'sum'], ['field' => 'id', 'aggregate' => 'count']], 'chart_type' => 'pie'],
            ['key' => 'hr-headcount', 'module' => 'Human Resources', 'name' => 'Headcount by status', 'source' => 'hr_employees', 'fields' => ['status'], 'filters' => [], 'groupings' => ['status'], 'metrics' => [['field' => 'id', 'aggregate' => 'count'], ['field' => 'base_salary', 'aggregate' => 'sum']], 'chart_type' => 'pie'],
            ['key' => 'crm-pipeline', 'module' => 'CRM', 'name' => 'Pipeline by status', 'source' => 'crm_opportunities', 'fields' => ['status'], 'filters' => [], 'groupings' => ['status'], 'metrics' => [['field' => 'value', 'aggregate' => 'sum'], ['field' => 'id', 'aggregate' => 'count']], 'chart_type' => 'bar'],
        ];
    }

    public function executeDefinition(int $companyId, ReportDefinition $report): array
    {
        return $this->execute($companyId, $report->only(['source', 'fields', 'filters', 'groupings', 'metrics', 'chart_type']));
    }

    public function execute(int $companyId, array $definition): array
    {
        $ttl = (int) config('reporting.cache_ttl', 300);
        $cacheKey = 'report:' . $companyId . ':' . md5(json_encode($definition));

        return Cache::remember($cacheKey, $ttl, fn () => $this->executeRaw($companyId, $definition));
    }

    public function bustCache(int $companyId, array $definition): void
    {
        Cache::forget('report:' . $companyId . ':' . md5(json_encode($definition)));
    }

    public function executeWithComparison(int $companyId, array $definition, string $compareTo): array
    {
        $sourceConfig = $this->sources()[$definition['source'] ?? ''] ?? null;
        if (! $sourceConfig) {
            throw ValidationException::withMessages(['source' => ['The selected reporting source is not available.']]);
        }

        $dateField = $sourceConfig['dateField'];
        [$fromDate, $toDate] = $this->extractDateRange($definition['filters'] ?? [], $dateField);

        [$prevFrom, $prevTo] = match ($compareTo) {
            'previous_period' => $this->shiftPeriod($fromDate, $toDate),
            'previous_year' => [$fromDate->copy()->subYear(), $toDate->copy()->subYear()],
            default => throw ValidationException::withMessages(['compare_to' => ['Invalid comparison period.']]),
        };

        $current = $this->execute($companyId, $definition);

        $prevDefinition = $definition;
        $prevDefinition['filters'] = $this->replaceDateFilter($prevDefinition['filters'] ?? [], $dateField, $prevFrom, $prevTo);
        $previous = $this->execute($companyId, $prevDefinition);

        return [
            'current' => $current,
            'previous' => $previous,
            'compare_to' => $compareTo,
            'current_period' => ['from' => $fromDate->toDateString(), 'to' => $toDate->toDateString()],
            'previous_period' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()],
        ];
    }

    private function executeRaw(int $companyId, array $definition): array
    {
        $source = $this->sources()[$definition['source'] ?? ''] ?? null;
        if (! $source) {
            throw ValidationException::withMessages(['source' => ['The selected reporting source is not available.']]);
        }

        $fields = array_values(array_unique($definition['fields'] ?? []));
        $filters = $definition['filters'] ?? [];
        $groupings = array_values(array_unique($definition['groupings'] ?? []));
        $metrics = $definition['metrics'] ?? [];

        $this->assertAllowed($fields, $source['fields'], 'fields');
        $this->assertAllowed($groupings, $source['dimensions'], 'groupings');
        foreach ($metrics as $metric) {
            if (! in_array($metric['field'] ?? '', $source['metrics'], true)) {
                throw ValidationException::withMessages(['metrics' => ['A selected metric is not available for this source.']]);
            }
        }

        $query = DB::table($source['table'])->where('company_id', $companyId);
        $this->applyFilters($query, $filters, $source['fields']);

        if ($groupings || $metrics) {
            $selects = $groupings;
            foreach ($metrics as $metric) {
                $aggregate = strtoupper($metric['aggregate']);
                $field = $metric['field'];
                $selects[] = DB::raw("{$aggregate}(`{$field}`) as `{$metric['aggregate']}_{$field}`");
            }
            if (! $metrics) {
                $selects[] = DB::raw('COUNT(*) as `count_id`');
            }
            $query->select($selects);
            if ($groupings) {
                $query->groupBy($groupings);
            }
        } else {
            $query->select($fields);
        }

        $rows = $query->limit(500)->get()->map(fn ($row) => (array) $row)->all();

        return [
            'source' => $definition['source'],
            'chart_type' => $definition['chart_type'] ?? 'table',
            'columns' => array_keys($rows[0] ?? []),
            'rows' => $rows,
            'row_count' => count($rows),
            'generated_at' => now()->toISOString(),
        ];
    }

    private function source(string $label, string $table, string $dateField, array $fields, array $dimensions, array $metrics): array
    {
        return compact('label', 'table', 'dateField', 'fields', 'dimensions', 'metrics');
    }

    private function assertAllowed(array $selected, array $allowed, string $key): void
    {
        if (array_diff($selected, $allowed)) {
            throw ValidationException::withMessages([$key => ['One or more selected fields are not available for this source.']]);
        }
    }

    private function applyFilters(Builder $query, array $filters, array $allowed): void
    {
        foreach ($filters as $filter) {
            $field = $filter['field'] ?? '';
            if (! in_array($field, $allowed, true)) {
                throw ValidationException::withMessages(['filters' => ['A filter field is not available for this source.']]);
            }
            $operator = $filter['operator'] ?? '=';
            $value = $filter['value'] ?? null;
            match ($operator) {
                'contains' => $query->where($field, 'like', "%{$value}%"),
                'starts_with' => $query->where($field, 'like', "{$value}%"),
                'ends_with' => $query->where($field, 'like', "%{$value}"),
                'in' => $query->whereIn($field, is_array($value) ? $value : explode(',', (string) $value)),
                'between' => $query->whereBetween($field, (array) $value),
                'is_null' => $query->whereNull($field),
                'not_null' => $query->whereNotNull($field),
                default => $query->where($field, $operator, $value),
            };
        }
    }

    private function extractDateRange(array $filters, string $dateField): array
    {
        foreach ($filters as $filter) {
            if ($filter['field'] === $dateField && $filter['operator'] === 'between' && is_array($filter['value'] ?? null)) {
                return [Carbon::parse($filter['value'][0]), Carbon::parse($filter['value'][1])];
            }
        }
        // Default: current month
        return [now()->startOfMonth(), now()->endOfMonth()];
    }

    private function shiftPeriod(Carbon $from, Carbon $to): array
    {
        $days = $from->diffInDays($to) + 1;

        return [$from->copy()->subDays($days), $to->copy()->subDays($days)];
    }

    private function replaceDateFilter(array $filters, string $dateField, Carbon $from, Carbon $to): array
    {
        $filters = array_filter($filters, fn ($f) => $f['field'] !== $dateField);
        $filters[] = ['field' => $dateField, 'operator' => 'between', 'value' => [$from->toDateString(), $to->toDateString()]];

        return array_values($filters);
    }
}
