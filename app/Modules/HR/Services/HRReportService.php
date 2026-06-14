<?php

namespace App\Modules\HR\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\PayrollItem;
use App\Modules\HR\Policies\HRPolicy;

class HRReportService
{
    public function __construct(private HRPolicy $policy) {}

    public function report(User $u, string $type): array
    {
        $c = $this->policy->companyId($u, 'hr.export');

        return match ($type) {
            'headcount' => [
                'total' => Employee::where('company_id', $c)->where('status', 'active')->count(),
                'by_department' => Employee::where('company_id', $c)
                    ->where('status', 'active')
                    ->selectRaw('department_id, count(*) total')
                    ->groupBy('department_id')
                    ->get(),
            ],
            'turnover' => [
                'terminated' => Employee::where('company_id', $c)->whereNotNull('termination_date')->count(),
                'total' => Employee::where('company_id', $c)->count(),
            ],
            'leave-usage' => LeaveRequest::where('company_id', $c)
                ->where('status', 'approved')
                ->selectRaw('employee_id, sum(days) days')
                ->groupBy('employee_id')
                ->get()
                ->toArray(),
            'payroll-summary' => PayrollItem::whereHas('run', fn ($query) => $query->where('company_id', $c))
                ->selectRaw('currency, sum(gross_salary) gross, sum(net_salary) net, sum(tax_withholding) tax')
                ->groupBy('currency')
                ->get()
                ->toArray(),
            default => abort(404, 'Unknown HR report.'),
        };
    }

    public function response(User $u, string $type, string $format)
    {
        $data = $this->report($u, $type);
        if ($format !== 'csv') {
            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'HR report loaded',
                'errors' => null,
                'meta' => [],
            ]);
        }

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');

            foreach ($data as $key => $value) {
                fputcsv($out, [$key, is_scalar($value) ? $value : json_encode($value)]);
            }

            fclose($out);
        }, "{$type}.csv", ['Content-Type' => 'text/csv']);
    }
}
