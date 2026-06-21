<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Models\AuditLog;
use App\Modules\Platform\Models\UsageMetric;
use App\Modules\Platform\Services\UsageService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComplianceController extends Controller
{
    public function audits(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin() || $request->user()->can('audit.view'), 403);

        return ApiResponse::success(
            AuditLog::query()
                ->when(! $request->user()->isSuperAdmin(), fn ($query) => $query->where('company_id', $request->user()->company_id))
                ->when($request->filled('event'), fn ($query) => $query->where('event', $request->string('event')))
                ->latest('id')
                ->paginate(min(max($request->integer('per_page', 25), 1), 100)),
            'Audit log loaded'
        );
    }

    public function usage(Request $request, UsageService $usage): JsonResponse
    {
        $company = $request->user()->company;
        abort_unless($company, 422, 'A company is required.');

        return ApiResponse::success([
            'summary' => $usage->summary($company),
            'history' => UsageMetric::where('company_id', $company->id)->latest('period_date')->limit(90)->get(),
        ], 'Usage loaded');
    }
}
