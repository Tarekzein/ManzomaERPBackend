<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\InventoryRequest;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryReportService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function __construct(private readonly StockMovementService $movements, private readonly InventoryReportService $reports) {}

    public function movements(Request $request)
    {
        return ApiResponse::success($this->movements->list($request->user()));
    }

    public function storeMovement(InventoryRequest $request)
    {
        return ApiResponse::success($this->movements->create($request->user(), $request->validated()), 'Stock movement recorded', status: 201);
    }

    public function onHand(Request $request)
    {
        return ApiResponse::success($this->reports->onHand($request->user()));
    }

    public function valuation(Request $request)
    {
        return ApiResponse::success($this->reports->valuation($request->user()));
    }

    public function alerts(Request $request)
    {
        return ApiResponse::success($this->reports->alerts($request->user()));
    }

    public function updateReorder(InventoryRequest $request, StockBalance $balance)
    {
        return ApiResponse::success($this->reports->setReorder($request->user(), $balance, $request->validated()), 'Reorder settings updated');
    }
}
