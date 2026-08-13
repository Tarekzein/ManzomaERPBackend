<?php

namespace App\Modules\POS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\POS\Services\PosReportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosReportController extends Controller
{
    public function __construct(private readonly PosReportService $reports) {}

    public function summary(Request $request): JsonResponse
    {
        return ApiResponse::success($this->reports->summary($request->user(), $this->filters($request)), 'POS summary loaded');
    }

    public function shifts(Request $request): JsonResponse
    {
        return ApiResponse::success($this->reports->shifts($request->user(), $this->filters($request)), 'Shift report loaded');
    }

    public function tenders(Request $request): JsonResponse
    {
        return ApiResponse::success($this->reports->tenders($request->user(), $this->filters($request)), 'Tender report loaded');
    }

    public function products(Request $request): JsonResponse
    {
        return ApiResponse::success($this->reports->products($request->user(), $this->filters($request)), 'Product report loaded');
    }

    public function taxes(Request $request): JsonResponse
    {
        return ApiResponse::success($this->reports->taxes($request->user(), $this->filters($request)), 'Tax report loaded');
    }

    public function margins(Request $request): JsonResponse
    {
        return ApiResponse::success($this->reports->margins($request->user(), $this->filters($request)), 'Margin report loaded');
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'register_id' => ['sometimes', 'nullable', 'integer'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
    }
}
