<?php

use App\Modules\HR\Http\Controllers\HRController;
use App\Modules\HR\Http\Controllers\HRReportController;
use App\Modules\HR\Http\Controllers\LeaveController;
use App\Modules\HR\Http\Controllers\PayrollController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('hr')->name('hr.')->group(function () {
    Route::get('departments', [HRController::class, 'departments'])->name('departments.index');
    Route::post('departments', [HRController::class, 'storeDepartment'])->name('departments.store');
    Route::put('departments/{department}', [HRController::class, 'updateDepartment'])->name('departments.update');
    Route::get('teams', [HRController::class, 'teams'])->name('teams.index');
    Route::post('teams', [HRController::class, 'storeTeam'])->name('teams.store');
    Route::put('teams/{team}', [HRController::class, 'updateTeam'])->name('teams.update');
    Route::get('employees', [HRController::class, 'employees'])->name('employees.index');
    Route::post('employees', [HRController::class, 'storeEmployee'])->name('employees.store');
    Route::get('employees/{employee}', [HRController::class, 'showEmployee'])->name('employees.show');
    Route::put('employees/{employee}', [HRController::class, 'updateEmployee'])->name('employees.update');
    Route::post('employees/{employee}/documents', [HRController::class, 'document'])->name('documents.store');
    Route::get('documents/versions/{version}/download', [HRController::class, 'downloadDocument'])->name('documents.download');
    Route::get('leave-types', [HRController::class, 'leaveTypes'])->name('leave-types.index');
    Route::post('leave-types', [HRController::class, 'storeLeaveType'])->name('leave-types.store');
    Route::put('leave-types/{leaveType}', [HRController::class, 'updateLeaveType'])->name('leave-types.update');
    Route::get('leave-requests', [LeaveController::class, 'index'])->name('leave.index');
    Route::post('leave-requests', [LeaveController::class, 'store'])->name('leave.store');
    Route::post('leave-requests/{leaveRequest}/review', [LeaveController::class, 'review'])->name('leave.review');
    Route::get('attendance', [HRController::class, 'attendance'])->name('attendance.index');
    Route::post('attendance', [HRController::class, 'storeAttendance'])->name('attendance.store');
    Route::get('payroll-runs', [PayrollController::class, 'index'])->name('payroll-runs.index');
    Route::post('payroll-runs', [PayrollController::class, 'store'])->name('payroll-runs.store');
    Route::post('payroll-runs/{run}/process', [PayrollController::class, 'process'])->name('payroll-runs.process');
    Route::get('payslips/{item}/pdf', [PayrollController::class, 'payslip'])->name('payslips.pdf');
    Route::post('payslips/{item}/email', [PayrollController::class, 'email'])->name('payslips.email');
    Route::get('jobs', [HRController::class, 'jobs'])->name('jobs.index');
    Route::post('jobs', [HRController::class, 'storeJob'])->name('jobs.store');
    Route::put('jobs/{job}', [HRController::class, 'updateJob'])->name('jobs.update');
    Route::post('jobs/{job}/applicants', [HRController::class, 'applicant'])->name('applicants.store');
    Route::put('applicants/{applicant}', [HRController::class, 'updateApplicant'])->name('applicants.update');
    Route::get('applicants/{applicant}/resume', [HRController::class, 'downloadResume'])->name('applicants.resume');
    Route::get('me', [HRController::class, 'me'])->name('self.show');
    Route::put('me', [HRController::class, 'updateMe'])->name('self.update');
    Route::get('me/leave-requests', [LeaveController::class, 'mine'])->name('self.leave');
    Route::get('me/payslips', [PayrollController::class, 'mine'])->name('self.payslips');
    Route::get('reports/{report}', [HRReportController::class, 'show'])->name('reports.show');
});
