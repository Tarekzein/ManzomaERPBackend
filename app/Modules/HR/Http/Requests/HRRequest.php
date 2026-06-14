<?php

namespace App\Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HRRequest extends FormRequest
{
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'hr.departments.store','hr.departments.update' => ['parent_id' => ['nullable', 'integer', 'exists:hr_departments,id'], 'manager_employee_id' => ['nullable', 'integer', 'exists:hr_employees,id'], 'code' => ['required', 'string', 'max:50'], 'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string']],
            'hr.teams.store','hr.teams.update' => ['department_id' => ['required', 'integer', 'exists:hr_departments,id'], 'manager_employee_id' => ['nullable', 'integer', 'exists:hr_employees,id'], 'code' => ['required', 'string', 'max:50'], 'name' => ['required', 'string', 'max:255']],
            'hr.employees.store','hr.employees.update' => ['user_id' => ['nullable', 'integer', 'exists:users,id'], 'department_id' => ['nullable', 'integer', 'exists:hr_departments,id'], 'team_id' => ['nullable', 'integer', 'exists:hr_teams,id'], 'manager_id' => ['nullable', 'integer', 'exists:hr_employees,id'], 'employee_number' => ['required', 'string', 'max:50'], 'name' => ['required', 'string', 'max:255'], 'email' => ['nullable', 'email'], 'phone' => ['nullable', 'string'], 'address' => ['nullable', 'array'], 'position' => ['nullable', 'string'], 'hire_date' => ['required', 'date'], 'termination_date' => ['nullable', 'date', 'after_or_equal:hire_date'], 'status' => ['required', Rule::in(['active', 'on_leave', 'terminated'])], 'base_salary' => ['required', 'numeric', 'min:0'], 'currency' => ['required', 'string', 'size:3'], 'payroll_formula' => ['nullable', 'array']],
            'hr.self.update' => ['phone' => ['nullable', 'string'], 'address' => ['nullable', 'array']],
            'hr.leave-types.store','hr.leave-types.update' => ['name' => ['required', 'string'], 'code' => ['required', 'string', 'max:50'], 'annual_allowance' => ['required', 'numeric', 'min:0'], 'is_paid' => ['required', 'boolean'], 'requires_approval' => ['required', 'boolean']],
            'hr.leave.store' => ['leave_type_id' => ['required', 'integer', 'exists:hr_leave_types,id'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on'], 'reason' => ['nullable', 'string']],
            'hr.leave.review' => ['status' => ['required', Rule::in(['approved', 'rejected'])], 'review_notes' => ['nullable', 'string']],
            'hr.attendance.store' => ['employee_id' => ['required', 'integer', 'exists:hr_employees,id'], 'work_date' => ['required', 'date'], 'clock_in' => ['nullable', 'date'], 'clock_out' => ['nullable', 'date', 'after:clock_in'], 'hours' => ['nullable', 'numeric', 'min:0', 'max:24'], 'source' => ['nullable', 'string', 'max:50'], 'notes' => ['nullable', 'string']],
            'hr.payroll-runs.store' => ['name' => ['required', 'string'], 'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'], 'pay_date' => ['required', 'date'], 'status' => ['nullable', Rule::in(['draft'])]],
            'hr.payroll-runs.process' => ['items' => ['nullable', 'array'], 'items.*.employee_id' => ['required', 'integer', 'exists:hr_employees,id'], 'items.*.bonuses' => ['nullable', 'numeric', 'min:0'], 'items.*.deductions' => ['nullable', 'numeric', 'min:0'], 'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100']],
            'hr.documents.store' => ['type' => ['required', 'string', 'max:100'], 'name' => ['required', 'string'], 'file' => ['required', 'file', 'max:20480'], 'notes' => ['nullable', 'string']],
            'hr.jobs.store','hr.jobs.update' => ['department_id' => ['nullable', 'integer', 'exists:hr_departments,id'], 'title' => ['required', 'string'], 'description' => ['nullable', 'string'], 'status' => ['required', Rule::in(['draft', 'open', 'closed'])], 'closes_on' => ['nullable', 'date']],
            'hr.applicants.store' => ['name' => ['required', 'string'], 'email' => ['required', 'email'], 'phone' => ['nullable', 'string'], 'stage' => ['nullable', Rule::in(['applied', 'screening', 'interview', 'offer', 'hired', 'rejected'])], 'notes' => ['nullable', 'string'], 'resume' => ['nullable', 'file', 'max:20480']],
            'hr.applicants.update' => ['stage' => ['required', Rule::in(['applied', 'screening', 'interview', 'offer', 'hired', 'rejected'])], 'notes' => ['nullable', 'string']],
            default => []
        };
    }
}
