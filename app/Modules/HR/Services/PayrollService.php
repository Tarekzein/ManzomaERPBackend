<?php

namespace App\Modules\HR\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\PayrollItem;
use App\Modules\HR\Models\PayrollRun;
use App\Modules\HR\Policies\HRPolicy;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class PayrollService
{
    public function __construct(private HRPolicy $policy, private PayrollCalculationService $calculator) {}

    public function list(User $u)
    {
        return PayrollRun::with('items.employee')->where('company_id', $this->policy->companyId($u, 'hr.payroll.view'))->latest()->get();
    }

    public function create(User $u, array $d): PayrollRun
    {
        return PayrollRun::create(['company_id' => $this->policy->companyId($u, 'hr.payroll.edit'), 'created_by' => $u->id] + $d);
    }

    public function process(User $u, PayrollRun $run, array $overrides = []): PayrollRun
    {
        $company = $this->policy->ensureOwned($u, $run, 'hr.payroll.edit');
        if ($run->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Only draft payroll runs can be processed.']]);
        }

        $invalidEmployee = collect($overrides)->pluck('employee_id')->first(
            fn ($employeeId) => ! Employee::where('company_id', $company)->whereKey($employeeId)->exists()
        );
        if ($invalidEmployee) {
            throw ValidationException::withMessages(['items' => ['Every payroll employee must belong to the company.']]);
        }

        return DB::transaction(function () use ($run, $company, $overrides) {
            foreach (Employee::where('company_id', $company)->where('status', 'active')->get() as $e) {
                $o = collect($overrides)->firstWhere('employee_id', $e->id) ?? [];
                PayrollItem::updateOrCreate(
                    ['payroll_run_id' => $run->id, 'employee_id' => $e->id],
                    $this->calculator->calculate($e, $run->period_start->toDateString(), $run->period_end->toDateString(), $o)
                );
            } $run->update(['status' => 'processed', 'processed_at' => now()]);

            return $run->load('items.employee');
        });
    }

    public function item(User $u, PayrollItem $item): PayrollItem
    {
        $item->load('run', 'employee');
        if ($item->employee->user_id === $u->id) {
            $this->policy->companyId($u);

            return $item;
        }

        $this->policy->ensureOwned($u, $item->run, 'hr.payroll.view');

        return $item;
    }

    public function mine(User $u)
    {
        return $this->policy->employee($u)->payrollItems()->with('run')->latest()->get();
    }

    public function pdf(User $u, PayrollItem $item)
    {
        return Pdf::loadView('hr.payslip', ['item' => $this->item($u, $item)]);
    }

    public function email(User $u, PayrollItem $item): PayrollItem
    {
        $item = $this->item($u, $item);
        if ($item->employee->user_id !== $u->id && ! $u->can('hr.payroll.edit')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('You cannot email this payslip.');
        }

        $email = $item->employee->email ?: $item->employee->user?->email;
        abort_unless($email, 422, 'Employee has no email address.');
        $pdf = $this->pdf($u, $item)->output();
        Mail::send('hr.payslip', ['item' => $item], fn ($m) => $m->to($email)->subject("Payslip - {$item->run->name}")->attachData($pdf, 'payslip.pdf', ['mime' => 'application/pdf']));
        $item->update(['emailed_at' => now()]);

        return $item->refresh();
    }
}
