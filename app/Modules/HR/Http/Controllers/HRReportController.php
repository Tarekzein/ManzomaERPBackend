<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Services\HRReportService;
use Illuminate\Http\Request;

class HRReportController extends Controller
{
    public function __construct(private HRReportService $reports) {}

    public function show(Request $r, string $report)
    {
        return $this->reports->response($r->user(), $report, $r->query('format', 'json'));
    }
}
