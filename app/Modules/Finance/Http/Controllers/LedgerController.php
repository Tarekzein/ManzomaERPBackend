<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Http\Requests\FinanceRequest;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\FinancialPeriod;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\LedgerService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class LedgerController extends Controller
{
    public function __construct(private readonly LedgerService $service) {}

    public function accounts(Request $r)
    {
        return ApiResponse::success($this->service->accounts($r->user()));
    }

    public function storeAccount(FinanceRequest $r)
    {
        return ApiResponse::success($this->service->createAccount($r->user(), $r->validated()), 'Account created', status: 201);
    }

    public function updateAccount(FinanceRequest $r, Account $account)
    {
        return ApiResponse::success($this->service->updateAccount($r->user(), $account, $r->validated()), 'Account updated');
    }

    public function periods(Request $r)
    {
        return ApiResponse::success($this->service->periods($r->user()));
    }

    public function storePeriod(FinanceRequest $r)
    {
        return ApiResponse::success($this->service->createPeriod($r->user(), $r->validated()), 'Period created', status: 201);
    }

    public function lockPeriod(Request $r, FinancialPeriod $period)
    {
        return ApiResponse::success($this->service->lockPeriod($r->user(), $period), 'Period locked');
    }

    public function journals(Request $r)
    {
        return ApiResponse::success($this->service->journals($r->user()));
    }

    public function storeJournal(FinanceRequest $r)
    {
        return ApiResponse::success($this->service->createJournal($r->user(), $r->validated()), 'Journal created', status: 201);
    }

    public function postJournal(Request $r, JournalEntry $journal)
    {
        return ApiResponse::success($this->service->post($r->user(),$journal), 'Journal posted');
    }
}
