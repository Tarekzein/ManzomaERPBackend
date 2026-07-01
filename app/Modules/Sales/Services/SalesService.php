<?php

namespace App\Modules\Sales\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\FinanceContact;
use App\Modules\Finance\Services\AccountingPostingService;
use App\Modules\Finance\Services\CompanyAccountResolver;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Sales\Models\GoodsReceipt;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Models\PurchaseOrder;
use App\Modules\Sales\Models\SalesContact;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesQuotation;
use App\Modules\Sales\Policies\SalesPolicy;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesService
{
    public function __construct(
        private readonly SalesPolicy $policy,
        private readonly StockMovementService $stock,
        private readonly InvoiceService $invoices,
        private readonly AccountingPostingService $accounting,
        private readonly CompanyAccountResolver $accounts,
        private readonly SalesDocumentCalculator $calculator,
    ) {}

    public function list(User $user, string $model, array $with = [])
    {
        return $model::with($with)->where('company_id', $this->policy->companyId($user))->latest()->get();
    }

    public function saveContact(User $user, array $data, ?SalesContact $contact = null): SalesContact
    {
        $companyId = $contact ? $this->policy->ensureOwned($user, $contact) : $this->policy->companyId($user, 'sales.create');
        $this->ensureFinanceContact($companyId, $data['finance_contact_id'] ?? null, $data['type'] === 'both' ? ['customer', 'vendor', 'both'] : [$data['type'], 'both']);

        return $contact ? tap($contact)->update($data) : SalesContact::create(['company_id' => $companyId] + $data);
    }

    public function savePriceList(User $user, array $data, ?PriceList $priceList = null): PriceList
    {
        $companyId = $priceList ? $this->policy->ensureOwned($user, $priceList) : $this->policy->companyId($user, 'sales.create');
        $this->ensureContact($companyId, $data['contact_id'] ?? null);

        return DB::transaction(function () use ($companyId, $data, $priceList) {
            $list = $priceList ?: new PriceList(['company_id' => $companyId]);
            $list->fill(collect($data)->except('items')->all())->save();
            $list->items()->delete();
            foreach ($data['items'] as $item) {
                Product::where('company_id', $companyId)->findOrFail($item['product_id']);
                $list->items()->create($item + ['discount_percent' => $item['discount_percent'] ?? 0]);
            }

            return $list->load('contact', 'items.product');
        });
    }

    public function saveQuotation(User $user, array $data, ?SalesQuotation $quote = null): SalesQuotation
    {
        $companyId = $quote ? $this->policy->ensureOwned($user, $quote) : $this->policy->companyId($user, 'sales.create');
        $this->ensureContact($companyId, $data['customer_id'], ['customer', 'both']);

        return DB::transaction(function () use ($companyId, $user, $data, $quote) {
            $quote ??= new SalesQuotation(['company_id' => $companyId, 'created_by' => $user->id, 'status' => 'draft']);
            $quote->fill(collect($data)->except('lines')->all() + ['number' => $data['number'] ?? $this->number('SQ')])->save();
            $this->replaceLines($companyId, $quote, $data['lines'], $data['customer_id']);

            return $this->recalculate($quote)->load('customer', 'lines.product');
        });
    }

    public function convertQuote(User $user, SalesQuotation $quote): SalesOrder
    {
        $companyId = $this->policy->ensureOwned($user, $quote);
        if (! in_array($quote->status, ['draft', 'sent', 'accepted'], true)) {
            throw ValidationException::withMessages(['status' => ['Only active quotations can be converted.']]);
        }

        return DB::transaction(function () use ($companyId, $user, $quote) {
            $order = SalesOrder::create([
                'company_id' => $companyId,
                'quotation_id' => $quote->id,
                'customer_id' => $quote->customer_id,
                'number' => $this->number('SO'),
                'order_date' => now()->toDateString(),
                'status' => 'draft',
                'currency' => $quote->currency,
                'notes' => $quote->notes,
                'created_by' => $user->id,
            ]);
            foreach ($quote->lines as $line) {
                $order->lines()->create($line->replicate(['document_id', 'document_type'])->toArray());
            }
            $quote->update(['status' => 'accepted']);

            return $this->recalculate($order)->load('customer', 'lines.product');
        });
    }

    public function saveSalesOrder(User $user, array $data, ?SalesOrder $order = null): SalesOrder
    {
        $companyId = $order ? $this->policy->ensureOwned($user, $order) : $this->policy->companyId($user, 'sales.create');
        if ($order && $order->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Only draft sales orders can be edited.']]);
        }
        $this->ensureContact($companyId, $data['customer_id'], ['customer', 'both']);
        $this->ensureWarehouse($companyId, $data['warehouse_id'] ?? null);

        return DB::transaction(function () use ($companyId, $user, $data, $order) {
            $order ??= new SalesOrder(['company_id' => $companyId, 'created_by' => $user->id, 'status' => 'draft']);
            $order->fill(collect($data)->except('lines')->all() + ['number' => $data['number'] ?? $this->number('SO')])->save();
            $this->replaceLines($companyId, $order, $data['lines'], $data['customer_id']);

            return $this->recalculate($order)->load('customer', 'warehouse', 'lines.product');
        });
    }

    public function confirmSalesOrder(User $user, SalesOrder $order): SalesOrder
    {
        $this->policy->ensureOwned($user, $order);
        if ($order->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Only draft sales orders can be confirmed.']]);
        }
        abort_unless($order->warehouse_id, 422, 'A warehouse is required before confirming the order.');

        return DB::transaction(function () use ($user, $order) {
            $movement = $this->stock->create($user, [
                'type' => 'issue',
                'reference' => $order->number,
                'notes' => "Sales order {$order->number}",
                'lines' => $order->lines->map(fn ($line) => [
                    'product_id' => $line->product_id,
                    'from_warehouse_id' => $order->warehouse_id,
                    'quantity' => $line->quantity,
                ])->all(),
            ]);
            $cogs = (float) $movement->lines->sum('total_cost');
            $this->accounting->postCogs($order->company_id, $user->id, now()->toDateString(), "COGS for sales order {$order->number}", $cogs, $order->currency, 'sales_order_cogs', $order->id);
            $order->update(['status' => 'confirmed', 'confirmed_at' => now(), 'stock_movement_id' => $movement->id]);

            return $order->refresh()->load('customer', 'warehouse', 'stockMovement.lines', 'lines.product');
        });
    }

    public function transitionSales(User $user, SalesOrder $order, string $status): SalesOrder
    {
        $this->policy->ensureOwned($user, $order);
        $allowed = [
            'confirmed' => ['shipped'],
            'shipped' => ['closed'],
            'invoiced' => ['closed'],
        ];
        if (! in_array($status, $allowed[$order->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => ["Sales order cannot move from {$order->status} to {$status}."]]);
        }

        $order->update(['status' => $status, "{$status}_at" => now()]);

        return $order->refresh()->load('customer', 'warehouse', 'lines.product');
    }

    public function invoiceSalesOrder(User $user, SalesOrder $order, array $data = []): SalesOrder
    {
        $this->policy->ensureOwned($user, $order);
        if (! in_array($order->status, ['confirmed', 'shipped'], true)) {
            throw ValidationException::withMessages(['status' => ['Only confirmed or shipped sales orders can be invoiced.']]);
        }

        return DB::transaction(function () use ($user, $order, $data) {
            $invoiceId = null;
            $contactId = $data['finance_contact_id'] ?? $order->customer->finance_contact_id;
            $accountId = $data['revenue_account_id'] ?? Account::where('company_id', $order->company_id)->where('code', '4000')->value('id');
            if ($contactId && $accountId) {
                $this->ensureFinanceContact($order->company_id, $contactId, ['customer', 'both']);
                $this->accounts->ensure($order->company_id, $accountId, 'revenue', 'Revenue account');
                $invoice = $this->invoices->createInvoice($user, [
                    'type' => 'receivable',
                    'contact_id' => $contactId,
                    'number' => $data['invoice_number'] ?? 'SI-'.$order->number,
                    'invoice_date' => now()->toDateString(),
                    'due_date' => now()->addDays(30)->toDateString(),
                    'currency' => $order->currency,
                    'exchange_rate' => 1,
                    'source_type' => 'sales_order',
                    'source_id' => $order->id,
                    'lines' => $order->lines->map(fn ($line) => [
                        'account_id' => $accountId,
                        'description' => $line->description ?: $line->product?->name,
                        'quantity' => $line->quantity,
                        'unit_price' => $line->unit_price,
                        'discount_percent' => $line->discount_percent,
                        'tax_percent' => $line->tax_percent,
                    ])->all(),
                ]);
                $invoiceId = $this->invoices->post($user, $invoice)->id;
            }
            $order->update(['status' => 'invoiced', 'invoiced_at' => now(), 'finance_invoice_id' => $invoiceId]);

            return $order->refresh()->load('customer', 'financeInvoice', 'lines.product');
        });
    }

    public function savePurchaseOrder(User $user, array $data, ?PurchaseOrder $order = null): PurchaseOrder
    {
        $companyId = $order ? $this->policy->ensureOwned($user, $order) : $this->policy->companyId($user, 'sales.create');
        if ($order && $order->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Only draft purchase orders can be edited.']]);
        }
        $this->ensureContact($companyId, $data['vendor_id'], ['vendor', 'both']);
        $this->ensureWarehouse($companyId, $data['warehouse_id'] ?? null);

        return DB::transaction(function () use ($companyId, $user, $data, $order) {
            $order ??= new PurchaseOrder(['company_id' => $companyId, 'created_by' => $user->id, 'status' => 'draft']);
            $order->fill(collect($data)->except('lines')->all() + ['number' => $data['number'] ?? $this->number('PO')])->save();
            $this->replaceLines($companyId, $order, $data['lines']);

            return $this->recalculate($order)->load('vendor', 'warehouse', 'lines.product');
        });
    }

    public function confirmPurchase(User $user, PurchaseOrder $order): PurchaseOrder
    {
        $this->policy->ensureOwned($user, $order);
        if ($order->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Only draft purchase orders can be confirmed.']]);
        }
        $order->update(['status' => 'confirmed', 'confirmed_at' => now()]);

        return $order->refresh()->load('vendor', 'warehouse', 'lines.product');
    }

    public function receivePurchase(User $user, PurchaseOrder $order, array $data): GoodsReceipt
    {
        $this->policy->ensureOwned($user, $order);
        if ($order->status !== 'confirmed') {
            throw ValidationException::withMessages(['status' => ['Only confirmed purchase orders can be received.']]);
        }
        abort_unless($order->warehouse_id, 422, 'A warehouse is required before receiving the order.');

        return DB::transaction(function () use ($user, $order, $data) {
            $lines = $data['lines'] ?? $order->lines->map(fn ($line) => ['purchase_order_line_id' => $line->id, 'quantity_received' => $line->quantity])->all();
            $movement = $this->stock->create($user, [
                'type' => 'receipt',
                'reference' => $order->number,
                'notes' => "Purchase order {$order->number}",
                'lines' => collect($lines)->map(function ($receiptLine) use ($order) {
                    $line = $order->lines->firstWhere('id', (int) $receiptLine['purchase_order_line_id']);
                    abort_unless($line, 422, 'Receipt line does not belong to the purchase order.');

                    return ['product_id' => $line->product_id, 'to_warehouse_id' => $order->warehouse_id, 'quantity' => $receiptLine['quantity_received'], 'unit_cost' => $line->unit_price];
                })->all(),
            ]);
            $receipt = GoodsReceipt::create(['company_id' => $order->company_id, 'purchase_order_id' => $order->id, 'stock_movement_id' => $movement->id, 'number' => $this->number('GR'), 'received_on' => $data['received_on'], 'notes' => $data['notes'] ?? null, 'received_by' => $user->id]);
            foreach ($lines as $receiptLine) {
                $line = $order->lines->firstWhere('id', (int) $receiptLine['purchase_order_line_id']);
                $receipt->lines()->create(['purchase_order_line_id' => $line->id, 'product_id' => $line->product_id, 'quantity_received' => $receiptLine['quantity_received']]);
            }
            $order->update(['status' => 'received', 'received_at' => now(), 'stock_movement_id' => $movement->id]);

            return $receipt->load('purchaseOrder.vendor', 'lines.product', 'stockMovement.lines');
        });
    }

    public function matchPurchase(User $user, PurchaseOrder $order, array $data): PurchaseOrder
    {
        $this->policy->ensureOwned($user, $order);
        if ($order->status !== 'received') {
            throw ValidationException::withMessages(['status' => ['Only received purchase orders can be matched.']]);
        }
        if (! $order->receipts()->exists()) {
            throw ValidationException::withMessages(['receipts' => ['A goods receipt is required before matching.']]);
        }
        foreach ($order->lines as $line) {
            $received = $order->receipts()
                ->whereHas('lines', fn ($query) => $query->where('purchase_order_line_id', $line->id))
                ->with('lines')
                ->get()
                ->flatMap->lines
                ->where('purchase_order_line_id', $line->id)
                ->sum('quantity_received');
            if ((float) $received < (float) $line->quantity) {
                throw ValidationException::withMessages(['receipts' => ['Received quantities must match the purchase order before matching.']]);
            }
        }

        return DB::transaction(function () use ($user, $order, $data) {
            $invoiceId = null;
            $contactId = $data['finance_contact_id'] ?? $order->vendor->finance_contact_id;
            $accountId = $data['expense_account_id'] ?? Account::where('company_id', $order->company_id)->where('code', '5000')->value('id');
            if ($contactId && $accountId) {
                $this->ensureFinanceContact($order->company_id, $contactId, ['vendor', 'both']);
                $this->accounts->ensure($order->company_id, $accountId, null, 'Purchase invoice account');
                $invoice = $this->invoices->createInvoice($user, [
                    'type' => 'payable',
                    'contact_id' => $contactId,
                    'number' => $data['invoice_number'] ?? 'VI-'.$order->number,
                    'invoice_date' => now()->toDateString(),
                    'due_date' => now()->addDays(30)->toDateString(),
                    'currency' => $order->currency,
                    'exchange_rate' => 1,
                    'source_type' => 'purchase_order',
                    'source_id' => $order->id,
                    'lines' => $order->lines->map(fn ($line) => [
                        'account_id' => $accountId,
                        'description' => $line->description ?: $line->product?->name,
                        'quantity' => $line->quantity,
                        'unit_price' => $line->unit_price,
                        'discount_percent' => $line->discount_percent,
                        'tax_percent' => $line->tax_percent,
                    ])->all(),
                ]);
                $invoiceId = $this->invoices->post($user, $invoice)->id;
            }
            $order->update(['status' => 'matched', 'matched_at' => now(), 'finance_invoice_id' => $invoiceId]);

            return $order->refresh()->load('vendor', 'receipts.lines', 'financeInvoice', 'lines.product');
        });
    }

    public function reports(User $user, string $type): array
    {
        $companyId = $this->policy->companyId($user, 'sales.export');

        return match ($type) {
            'order-volume' => ['sales_orders' => SalesOrder::where('company_id', $companyId)->count(), 'purchase_orders' => PurchaseOrder::where('company_id', $companyId)->count()],
            'revenue-by-period' => SalesOrder::where('company_id', $companyId)->whereIn('status', ['invoiced', 'closed'])->selectRaw('DATE_FORMAT(order_date, "%Y-%m") period, sum(total) revenue')->groupBy('period')->orderBy('period')->get()->toArray(),
            'top-customers' => SalesOrder::with('customer')->where('company_id', $companyId)->selectRaw('customer_id, sum(total) revenue, count(*) orders')->groupBy('customer_id')->orderByDesc('revenue')->limit(10)->get()->toArray(),
            default => abort(404, 'Unknown sales report.'),
        };
    }

    public function creditSalesOrder(User $user, SalesOrder $order, array $data)
    {
        $this->policy->ensureOwned($user, $order);
        if (! $order->finance_invoice_id) {
            throw ValidationException::withMessages(['order' => ['This sales order has no posted finance invoice to credit.']]);
        }

        return $this->invoices->credit($user, $order->financeInvoice, $data);
    }

    public function pdf(User $user, Model $document, string $view)
    {
        $this->policy->ensureOwned($user, $document);

        $relations = ['lines.product'];
        if (method_exists($document, 'customer')) {
            $relations[] = 'customer';
        }
        if (method_exists($document, 'vendor')) {
            $relations[] = 'vendor';
        }
        if (method_exists($document, 'warehouse')) {
            $relations[] = 'warehouse';
        }

        return Pdf::loadView($view, ['document' => $document->loadMissing($relations)]);
    }

    private function replaceLines(int $companyId, Model $document, array $lines, ?int $customerId = null): void
    {
        $document->lines()->delete();
        foreach ($lines as $line) {
            $product = Product::where('company_id', $companyId)->findOrFail($line['product_id']);
            $price = $this->calculator->price($companyId, $product, $customerId, $line);
            $totals = $this->calculator->totals((float) $line['quantity'], (float) $price['unit_price'], (float) $price['discount_percent'], (float) ($line['tax_percent'] ?? 0));
            $document->lines()->create($line + $price + ['description' => $line['description'] ?? $product->name] + $totals);
        }
    }

    private function recalculate(Model $document): Model
    {
        $lines = $document->lines;
        $document->update(['subtotal' => $lines->sum('subtotal'), 'discount_total' => $lines->sum('discount_amount'), 'tax_total' => $lines->sum('tax_amount'), 'total' => $lines->sum('total')]);

        return $document->refresh();
    }

    private function number(string $prefix): string
    {
        return $prefix.'-'.now()->format('YmdHis').'-'.random_int(100, 999);
    }

    private function ensureContact(int $companyId, ?int $id, array $types = ['customer', 'vendor', 'both']): void
    {
        if ($id && ! SalesContact::where('company_id', $companyId)->whereIn('type', $types)->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['contact_id' => ['The selected contact must belong to the company and match the required type.']]);
        }
    }

    private function ensureFinanceContact(int $companyId, ?int $id, array $types = ['customer', 'vendor', 'both']): void
    {
        if ($id && ! FinanceContact::where('company_id', $companyId)->whereIn('type', $types)->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['finance_contact_id' => ['The selected finance contact must belong to the company and match the required type.']]);
        }
    }

    private function ensureWarehouse(int $companyId, ?int $id): void
    {
        if ($id && ! Warehouse::where('company_id', $companyId)->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['warehouse_id' => ['The selected warehouse must belong to the company.']]);
        }
    }
}
