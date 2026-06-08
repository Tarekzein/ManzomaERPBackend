<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Http\Requests\FinanceRequest;
use App\Modules\Finance\Models\BankTransaction;
use App\Modules\Finance\Services\FinanceOperationsService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class FinanceOperationsController extends Controller
{
    public function __construct(private readonly FinanceOperationsService $service) {}

    public function taxes(Request $r)
    {
        return ApiResponse::success($this->service->taxes($r->user()));
    }

    public function storeTax(FinanceRequest $r)
    {
        return ApiResponse::success($this->service->createTax($r->user(), $r->validated()), 'Tax created', status: 201);
    }

    public function rates(Request $r)
    {
        return ApiResponse::success($this->service->rates($r->user()));
    }

    public function storeRate(FinanceRequest $r)
    {
        return ApiResponse::success($this->service->createRate($r->user(), $r->validated()), 'Exchange rate saved', status: 201);
    }

    public function syncRates(Request $r)
    {
        $d = $r->validate(['quotes' => ['required', 'array', 'min:1'], 'quotes.*' => ['string', 'size:3']]);

        return ApiResponse::success($this->service->syncRates($r->user(), $d['quotes']), 'Exchange rates synchronized');
    }

    public function banks(Request $r)
    {
        return ApiResponse::success($this->service->banks($r->user()));
    }

    public function storeBank(FinanceRequest $r)
    {
        return ApiResponse::success($this->service->createBank($r->user(), $r->validated()), 'Bank account created', status: 201);
    }

    public function storeTransaction(FinanceRequest $r)
    {
        return ApiResponse::success($this->service->createTransaction($r->user(), $r->validated()), 'Bank transaction created', status: 201);
    }

    public function reconcile(FinanceRequest $r, BankTransaction $transaction)
    {
        return ApiResponse::success($this->service->reconcile($r->user(), $transaction, $r->validated()), 'Bank transaction reconciled');
    }

    public function budgets(Request $r)
    {
        return ApiResponse::success($this->service->budgets($r->user()));
    }

    public function storeBudget(FinanceRequest $r)
    {
        return ApiResponse::success($this->service->createBudget($r->user(), $r->validated()), 'Budget created', status: 201);
    }
}
