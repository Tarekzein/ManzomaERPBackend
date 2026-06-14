<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Requests\HRRequest;
use App\Modules\HR\Models\PayrollItem;
use App\Modules\HR\Models\PayrollRun;
use App\Modules\HR\Services\PayrollService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    public function __construct(private PayrollService $payroll) {}

    public function index(Request $r)
    {
        return ApiResponse::success($this->payroll->list($r->user()));
    }

    public function store(HRRequest $r)
    {
        return ApiResponse::success($this->payroll->create($r->user(), $r->validated()), 'Payroll run created', status: 201);
    }

    public function process(HRRequest $r, PayrollRun $run)
    {
        return ApiResponse::success($this->payroll->process($r->user(), $run, $r->validated('items', [])), 'Payroll processed');
    }

    public function mine(Request $r)
    {
        return ApiResponse::success($this->payroll->mine($r->user()));
    }

    public function payslip(Request $r, PayrollItem $item)
    {
        return $this->payroll->pdf($r->user(), $item)->download("payslip-{$item->id}.pdf");
    }

    public function email(Request $r, PayrollItem $item)
    {
        return ApiResponse::success($this->payroll->email($r->user(), $item), 'Payslip emailed');
    }
}
