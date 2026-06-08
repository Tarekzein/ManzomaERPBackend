<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Exports\FinancialStatementExport;
use App\Modules\Finance\Models\Budget;
use App\Modules\Finance\Services\FinancialReportService;
use App\Support\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class FinancialReportController extends Controller
{
    public function __construct(private readonly FinancialReportService $service) {}

    public function trialBalance(Request $r)
    {
        return ApiResponse::success($this->service->trialBalance($r->user(), $r->query('from'), $r->query('to')));
    }

    public function statement(Request $r, string $statement)
    {
        $data = $this->service->statement($r->user(), $statement, $r->query('from'), $r->query('to'));
        $format = $r->query('format', 'json');
        if ($format === 'pdf') {
            return Pdf::loadView('finance.statement', ['title' => ucwords(str_replace('-', ' ', $statement)), 'data' => $data])->download("$statement.pdf");
        }if ($format === 'xlsx') {
            return Excel::download(new FinancialStatementExport($data), "$statement.xlsx");
        }

        return ApiResponse::success($data);
    }

    public function variance(Request $r, Budget $budget)
    {
        return ApiResponse::success($this->service->variance($r->user(), $budget));
    }

    public function aging(Request $r, string $type)
    {
        return ApiResponse::success($this->service->aging($r->user(), $type));
    }
}
