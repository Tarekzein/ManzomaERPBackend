<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Models\PurchaseOrder;
use App\Modules\Sales\Models\SalesContact;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesQuotation;
use App\Modules\Sales\Services\SalesService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class SalesController extends Controller
{
    public function __construct(private readonly SalesService $sales) {}

    public function contacts(Request $request)
    {
        return ApiResponse::success($this->sales->list($request->user(), SalesContact::class, ['financeContact']));
    }

    public function storeContact(SalesRequest $request)
    {
        return ApiResponse::success($this->sales->saveContact($request->user(), $request->validated()), 'Sales contact created', status: 201);
    }

    public function updateContact(SalesRequest $request, SalesContact $contact)
    {
        return ApiResponse::success($this->sales->saveContact($request->user(), $request->validated(), $contact), 'Sales contact updated');
    }

    public function priceLists(Request $request)
    {
        return ApiResponse::success($this->sales->list($request->user(), PriceList::class, ['contact', 'items.product']));
    }

    public function storePriceList(SalesRequest $request)
    {
        return ApiResponse::success($this->sales->savePriceList($request->user(), $request->validated()), 'Price list saved', status: 201);
    }

    public function updatePriceList(SalesRequest $request, PriceList $priceList)
    {
        return ApiResponse::success($this->sales->savePriceList($request->user(), $request->validated(), $priceList), 'Price list updated');
    }

    public function quotations(Request $request)
    {
        return ApiResponse::success($this->sales->list($request->user(), SalesQuotation::class, ['customer', 'lines.product']));
    }

    public function storeQuotation(SalesRequest $request)
    {
        return ApiResponse::success($this->sales->saveQuotation($request->user(), $request->validated()), 'Quotation created', status: 201);
    }

    public function updateQuotation(SalesRequest $request, SalesQuotation $quotation)
    {
        return ApiResponse::success($this->sales->saveQuotation($request->user(), $request->validated(), $quotation), 'Quotation updated');
    }

    public function convertQuotation(Request $request, SalesQuotation $quotation)
    {
        return ApiResponse::success($this->sales->convertQuote($request->user(), $quotation), 'Quotation converted to sales order', status: 201);
    }

    public function quotationPdf(Request $request, SalesQuotation $quotation)
    {
        return $this->sales->pdf($request->user(), $quotation, 'sales.quotation')->download("quotation-{$quotation->number}.pdf");
    }

    public function orders(Request $request)
    {
        return ApiResponse::success($this->sales->list($request->user(), SalesOrder::class, ['customer', 'warehouse', 'financeInvoice', 'stockMovement', 'lines.product']));
    }

    public function storeOrder(SalesRequest $request)
    {
        return ApiResponse::success($this->sales->saveSalesOrder($request->user(), $request->validated()), 'Sales order created', status: 201);
    }

    public function updateOrder(SalesRequest $request, SalesOrder $order)
    {
        return ApiResponse::success($this->sales->saveSalesOrder($request->user(), $request->validated(), $order), 'Sales order updated');
    }

    public function confirmOrder(Request $request, SalesOrder $order)
    {
        return ApiResponse::success($this->sales->confirmSalesOrder($request->user(), $order), 'Sales order confirmed and stock issued');
    }

    public function shipOrder(Request $request, SalesOrder $order)
    {
        return ApiResponse::success($this->sales->transitionSales($request->user(), $order, 'shipped'), 'Sales order shipped');
    }

    public function invoiceOrder(SalesRequest $request, SalesOrder $order)
    {
        return ApiResponse::success($this->sales->invoiceSalesOrder($request->user(), $order, $request->validated()), 'Sales order invoiced');
    }

    public function creditOrder(SalesRequest $request, SalesOrder $order)
    {
        return ApiResponse::success($this->sales->creditSalesOrder($request->user(), $order, $request->validated()), 'Sales order credit posted', status: 201);
    }

    public function closeOrder(Request $request, SalesOrder $order)
    {
        return ApiResponse::success($this->sales->transitionSales($request->user(), $order, 'closed'), 'Sales order closed');
    }

    public function invoicePdf(Request $request, SalesOrder $order)
    {
        return $this->sales->pdf($request->user(), $order, 'sales.invoice')->download("sales-invoice-{$order->number}.pdf");
    }

    public function deliveryNotePdf(Request $request, SalesOrder $order)
    {
        return $this->sales->pdf($request->user(), $order, 'sales.delivery-note')->download("delivery-note-{$order->number}.pdf");
    }

    public function purchaseOrders(Request $request)
    {
        return ApiResponse::success($this->sales->list($request->user(), PurchaseOrder::class, ['vendor', 'warehouse', 'financeInvoice', 'stockMovement', 'receipts.lines.product', 'lines.product']));
    }

    public function storePurchaseOrder(SalesRequest $request)
    {
        return ApiResponse::success($this->sales->savePurchaseOrder($request->user(), $request->validated()), 'Purchase order created', status: 201);
    }

    public function updatePurchaseOrder(SalesRequest $request, PurchaseOrder $purchaseOrder)
    {
        return ApiResponse::success($this->sales->savePurchaseOrder($request->user(), $request->validated(), $purchaseOrder), 'Purchase order updated');
    }

    public function confirmPurchaseOrder(Request $request, PurchaseOrder $purchaseOrder)
    {
        return ApiResponse::success($this->sales->confirmPurchase($request->user(), $purchaseOrder), 'Purchase order confirmed');
    }

    public function receivePurchaseOrder(SalesRequest $request, PurchaseOrder $purchaseOrder)
    {
        return ApiResponse::success($this->sales->receivePurchase($request->user(), $purchaseOrder, $request->validated()), 'Goods receipt created and stock updated', status: 201);
    }

    public function matchPurchaseOrder(SalesRequest $request, PurchaseOrder $purchaseOrder)
    {
        return ApiResponse::success($this->sales->matchPurchase($request->user(), $purchaseOrder, $request->validated()), 'Purchase order matched');
    }

    public function purchaseOrderPdf(Request $request, PurchaseOrder $purchaseOrder)
    {
        return $this->sales->pdf($request->user(), $purchaseOrder, 'sales.purchase-order')->download("purchase-order-{$purchaseOrder->number}.pdf");
    }

    public function report(Request $request, string $report)
    {
        return ApiResponse::success($this->sales->reports($request->user(), $report), 'Sales report loaded');
    }
}
