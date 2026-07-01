<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Http\Requests\FinanceRequest;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class PayablesReceivablesController extends Controller
{
    public function __construct(private readonly InvoiceService $service) {}

    public function contacts(Request $r)
    {
        return ApiResponse::success($this->service->contacts($r->user()));
    }

    public function storeContact(FinanceRequest $r)
    {
        return ApiResponse::success($this->service->createContact($r->user(), $r->validated()), 'Contact created', status: 201);
    }

    public function invoices(Request $r)
    {
        return ApiResponse::success($this->service->invoices($r->user(), $r->query('type')));
    }

    public function storeInvoice(FinanceRequest $r)
    {
        return ApiResponse::success($this->service->createInvoice($r->user(), $r->validated()), 'Invoice created', status: 201);
    }

    public function postInvoice(Request $r, Invoice $invoice)
    {
        return ApiResponse::success($this->service->post($r->user(), $invoice), 'Invoice posted');
    }

    public function storePayment(FinanceRequest $r)
    {
        return ApiResponse::success($this->service->pay($r->user(), $r->validated()), 'Payment recorded', status: 201);
    }

    public function creditInvoice(FinanceRequest $r, Invoice $invoice)
    {
        return ApiResponse::success($this->service->credit($r->user(), $invoice, $r->validated()), 'Credit note posted', status: 201);
    }

    public function schedules(Request $r)
    {
        return ApiResponse::success($this->service->schedules($r->user()));
    }

    public function storeSchedule(FinanceRequest $r)
    {
        return ApiResponse::success($this->service->schedule($r->user(), $r->validated()), 'Payment scheduled', status: 201);
    }
}
