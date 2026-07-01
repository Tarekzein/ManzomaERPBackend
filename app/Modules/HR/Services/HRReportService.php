<?php

namespace App\Modules\HR\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeBenefit;
use App\Modules\HR\Models\EmployeeContract;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\PayrollItem;
use App\Modules\HR\Models\PerformanceReview;
use App\Modules\HR\Models\TrainingRecord;
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
                'by_department' => Employee::where('hr_employees.company_id', $c)
                    ->leftJoin('hr_departments', 'hr_departments.id', '=', 'hr_employees.department_id')
                    ->where('hr_employees.status', 'active')
                    ->selectRaw("coalesce(hr_departments.name, 'Unassigned') department, count(*) total")
                    ->groupBy('hr_departments.name')
                    ->get(),
            ],
            'turnover' => [
                'terminated' => Employee::where('company_id', $c)->whereNotNull('termination_date')->count(),
                'total' => Employee::where('company_id', $c)->count(),
            ],
            'leave-usage' => LeaveRequest::where('hr_leave_requests.company_id', $c)
                ->leftJoin('hr_employees', 'hr_employees.id', '=', 'hr_leave_requests.employee_id')
                ->leftJoin('hr_leave_types', 'hr_leave_types.id', '=', 'hr_leave_requests.leave_type_id')
                ->where('hr_leave_requests.status', 'approved')
                ->selectRaw('hr_employees.name employee, hr_leave_types.name leave_type, sum(hr_leave_requests.days) days')
                ->groupBy('hr_employees.name', 'hr_leave_types.name')
                ->get()
                ->toArray(),
            'payroll-summary' => PayrollItem::whereHas('run', fn ($query) => $query->where('company_id', $c))
                ->selectRaw('currency, sum(gross_salary) gross, sum(net_salary) net, sum(tax_withholding) tax')
                ->groupBy('currency')
                ->get()
                ->toArray(),
            'contracts' => EmployeeContract::query()
                ->leftJoin('hr_employees', 'hr_employees.id', '=', 'hr_employee_contracts.employee_id')
                ->where('hr_employee_contracts.company_id', $c)
                ->select('hr_employees.name as employee', 'hr_employee_contracts.contract_number', 'hr_employee_contracts.type', 'hr_employee_contracts.starts_on', 'hr_employee_contracts.ends_on', 'hr_employee_contracts.salary', 'hr_employee_contracts.currency', 'hr_employee_contracts.status')
                ->latest('hr_employee_contracts.id')
                ->get()
                ->toArray(),
            'benefits' => EmployeeBenefit::query()
                ->leftJoin('hr_employees', 'hr_employees.id', '=', 'hr_employee_benefits.employee_id')
                ->leftJoin('hr_benefits', 'hr_benefits.id', '=', 'hr_employee_benefits.benefit_id')
                ->where('hr_employee_benefits.company_id', $c)
                ->select('hr_employees.name as employee', 'hr_benefits.name as benefit', 'hr_employee_benefits.amount', 'hr_employee_benefits.status')
                ->get()
                ->toArray(),
            'training' => TrainingRecord::query()
                ->leftJoin('hr_employees', 'hr_employees.id', '=', 'hr_training_records.employee_id')
                ->where('hr_training_records.company_id', $c)
                ->select('hr_employees.name as employee', 'hr_training_records.title', 'hr_training_records.provider', 'hr_training_records.status', 'hr_training_records.started_on', 'hr_training_records.completed_on', 'hr_training_records.cost', 'hr_training_records.currency')
                ->latest('hr_training_records.id')
                ->get()
                ->toArray(),
            'performance' => PerformanceReview::query()
                ->leftJoin('hr_employees', 'hr_employees.id', '=', 'hr_performance_reviews.employee_id')
                ->where('hr_performance_reviews.company_id', $c)
                ->select('hr_employees.name as employee', 'hr_performance_reviews.period', 'hr_performance_reviews.score', 'hr_performance_reviews.status', 'hr_performance_reviews.reviewed_on')
                ->latest('hr_performance_reviews.id')
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
