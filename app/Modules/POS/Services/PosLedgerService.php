<?php

namespace App\Modules\POS\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\AccountingPostingService;
use App\Modules\Finance\Services\CompanyAccountResolver;
use App\Modules\Finance\Services\LedgerService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\ReorderAlertService;
use App\Modules\Inventory\Services\Valuation\ValuationStrategyFactory;
use App\Modules\Platform\Services\DocumentNumberService;
use App\Modules\POS\Models\PosRegister;
use App\Modules\POS\Models\PosSale;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Trusted internal operations for POS.
 *
 * A cashier holds `pos.sell` and nothing else. They are not given
 * `inventory.create` or `finance.create`, because those would let them post
 * arbitrary stock movements and journals outside the till. This service is the
 * narrow, audited path that performs exactly the postings a sale requires —
 * authorization has already happened in PosPolicy before anything here runs.
 *
 * It writes through the same models Inventory and Finance own, so a POS sale is
 * visible to every existing report and reconciliation.
 */
class PosLedgerService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ValuationStrategyFactory $valuations,
        private readonly ReorderAlertService $alerts,
        private readonly CompanyAccountResolver $accounts,
        private readonly LedgerService $ledger,
        private readonly AccountingPostingService $posting,
        private readonly PosAccountResolver $posAccounts,
    ) {}

    /**
     * Issue the sold stock and post its cost.
     *
     * Balances arrive already locked by the checkout transaction, so the
     * valuation and decrement here are serialized against any other basket
     * holding the same products.
     *
     * @param  array<int, StockBalance>  $balances  keyed by product id
     */
    public function issueStock(User $user, PosSale $sale, PosRegister $register, array $balances): StockMovement
    {
        $companyId = (int) $sale->company_id;

        $movement = StockMovement::query()->create([
            'company_id' => $companyId,
            'number' => $this->numbers->next($companyId, 'SM', $sale->business_date->format('Y')),
            'type' => 'issue',
            'reason_code' => 'pos_sale',
            'reference' => $sale->uuid,
            // Resolvable provenance, so a return can find what it reverses.
            'source_type' => PosSale::class,
            'source_id' => $sale->getKey(),
            'notes' => 'Point of sale',
            'occurred_at' => now(),
            'created_by' => $user->getKey(),
        ]);

        $costTotal = Decimal::zero();

        foreach ($sale->lines as $line) {
            $balance = $balances[(int) $line->product_id];
            $quantity = Decimal::of($line->quantity);
            $product = Product::query()->findOrFail($line->product_id);

            // Real issue cost from the valuation strategy (FIFO/LIFO/average),
            // not the snapshot estimate taken while pricing.
            [$unitCost, $lineCost] = $this->issueCost($product, $balance, $quantity);

            $movement->lines()->create([
                'product_id' => $line->product_id,
                'quantity' => $quantity->toString(),
                'from_warehouse_id' => $register->warehouse_id,
                'from_location_id' => $register->location_id,
                'unit_cost' => $unitCost->toString(),
                'total_cost' => $lineCost->toString(),
            ]);

            $balance->decrement('quantity', $quantity->toFloat());
            $this->alerts->evaluate($balance->refresh());

            // Correct the snapshot so COGS and margin reporting use the cost
            // actually relieved from inventory.
            $line->forceFill([
                'unit_cost' => $unitCost->toString(),
                'cost_total' => $lineCost->toString(),
            ])->save();

            $costTotal = $costTotal->plus($lineCost);
        }

        $cogs = $this->posting->postCogs(
            $companyId,
            $user->getKey(),
            $sale->business_date->toDateString(),
            "POS sale {$sale->uuid}",
            $costTotal->toFloat(),
            $sale->currency,
            PosSale::class,
            $sale->getKey(),
        );

        $sale->forceFill([
            'stock_movement_id' => $movement->getKey(),
            'cogs_journal_entry_id' => $cogs?->getKey(),
            'cost_total' => $costTotal->toString(),
        ])->save();

        return $movement;
    }

    /** @return array{Decimal, Decimal} unit cost, extended line cost */
    private function issueCost(
        Product $product,
        StockBalance $balance,
        Decimal $quantity,
    ): array {
        $unitCost = Decimal::of(
            $this->valuations->make($product->valuation_method)->issue($balance, $quantity->toFloat())
        );

        return [$unitCost, $unitCost->times($quantity)];
    }

    /**
     * Post the receivable invoice and one payment per tender.
     *
     * The invoice is the authoritative revenue document; the sale is the retail
     * record that points at it.
     */
    public function postInvoiceAndPayments(User $user, PosSale $sale, PosRegister $register): Invoice
    {
        $companyId = (int) $sale->company_id;
        $revenue = $this->accounts->byCode($companyId, '4000', 'revenue', 'Sales revenue account');
        $receivable = $this->accounts->byCode($companyId, '1100', 'asset', 'Accounts receivable');
        $taxAccount = Decimal::of($sale->tax_total)->isPositive()
            ? $this->accounts->byCode($companyId, '2100', 'liability', 'Tax payable account')
            : null;

        $net = Decimal::of($sale->subtotal)
            ->minus($sale->discount_total)
            ->plus($sale->rounding_total);

        $invoice = Invoice::query()->create([
            'company_id' => $companyId,
            // Retail is mostly anonymous, but an invoice always needs a
            // counterparty. A named customer is used when the cashier selected
            // one; otherwise the company's walk-in contact stands in.
            'contact_id' => $this->resolveInvoiceContactId($sale),
            'number' => $this->numbers->next($companyId, 'INV', $sale->business_date->format('Y')),
            'type' => 'receivable',
            'status' => 'draft',
            'invoice_date' => $sale->business_date->toDateString(),
            'due_date' => $sale->business_date->toDateString(),
            'currency' => $sale->currency,
            'exchange_rate' => 1,
            // Finance headers retain the same gross/discount semantics as
            // ordinary invoices. Rounding has no legacy header column, so it
            // is included in subtotal while the exact customer total remains
            // authoritative in total.
            'subtotal' => Decimal::of($sale->subtotal)->plus($sale->rounding_total)->toString(),
            'discount_total' => $sale->discount_total,
            'tax_total' => $sale->tax_total,
            'total' => $sale->total,
            'paid_total' => 0,
            'notes' => "POS receipt {$sale->uuid}",
            'source_type' => PosSale::class,
            'source_id' => $sale->getKey(),
        ]);

        foreach ($sale->lines as $line) {
            $invoice->lines()->create([
                'account_id' => $revenue->id,
                'description' => $line->product_name,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'discount_percent' => 0,
                'discount_amount' => $line->discount_amount,
                'subtotal' => Decimal::of($line->unit_price)->times($line->quantity)->toString(),
                'tax_amount' => $line->tax_amount,
                'total' => $line->line_total,
            ]);
        }

        // Post the revenue journal directly: this is a system posting, not a
        // user-initiated one, so it does not run the finance permission gate.
        $lines = [
            ['account_id' => $receivable->id, 'debit' => $sale->total, 'credit' => 0, 'currency' => $sale->currency, 'exchange_rate' => 1],
            ['account_id' => $revenue->id, 'debit' => 0, 'credit' => $net->toString(), 'currency' => $sale->currency, 'exchange_rate' => 1],
        ];

        if ($taxAccount) {
            $lines[] = ['account_id' => $taxAccount->id, 'debit' => 0, 'credit' => $sale->tax_total, 'currency' => $sale->currency, 'exchange_rate' => 1];
        }

        $entry = $this->ledger->createForCompany(
            $companyId,
            $user->getKey(),
            [
                'entry_date' => $sale->business_date->toDateString(),
                'description' => "POS invoice {$invoice->number}",
                'lines' => $lines,
            ],
            'invoice',
            $invoice->getKey(),
        );
        $this->ledger->postSystem($entry, $user->getKey());
        $invoice->forceFill(['status' => 'posted', 'journal_entry_id' => $entry->getKey()])->save();

        $this->recordPayments($user, $sale, $invoice, $register);
        $sale->forceFill(['invoice_id' => $invoice->getKey()])->save();

        return $invoice;
    }

    private function recordPayments(User $user, PosSale $sale, Invoice $invoice, PosRegister $register): void
    {
        $methods = $register->paymentMethods()->get()->keyBy('tender_type');
        $receivable = $this->accounts->byCode((int) $sale->company_id, '1100', 'asset', 'Accounts receivable');
        $paid = Decimal::zero();

        foreach ($sale->tenders as $tender) {
            $method = $methods->get($tender->tender_type);

            // Cash lands in the drawer account; card and wallet wait in a
            // clearing account until the provider settles.
            $account = $this->posAccounts->forTender(
                (int) $sale->company_id,
                $method?->settlesToDrawer() ? $method?->account_id : ($method?->clearing_account_id ?? $method?->account_id),
                (bool) $method?->settlesToDrawer(),
                $sale->currency,
            );

            $payment = Payment::query()->create([
                'company_id' => $sale->company_id,
                'invoice_id' => $invoice->getKey(),
                'account_id' => $account->id,
                'payment_date' => $sale->business_date->toDateString(),
                'amount' => $tender->amount,
                'currency' => $sale->currency,
                'exchange_rate' => 1,
                // The tender type lives on pos_tenders; the reference is what
                // ties this payment back to the receipt.
                'reference' => $tender->reference ?? "POS {$sale->uuid}",
            ]);

            $entry = $this->ledger->createForCompany(
                (int) $sale->company_id,
                $user->getKey(),
                [
                    'entry_date' => $sale->business_date->toDateString(),
                    'description' => "POS payment for {$invoice->number}",
                    'lines' => [
                        ['account_id' => $account->id, 'debit' => $tender->amount, 'credit' => 0, 'currency' => $sale->currency, 'exchange_rate' => 1],
                        ['account_id' => $receivable->id, 'debit' => 0, 'credit' => $tender->amount, 'currency' => $sale->currency, 'exchange_rate' => 1],
                    ],
                ],
                'payment',
                $payment->getKey(),
            );
            $this->ledger->postSystem($entry, $user->getKey());

            $payment->forceFill(['journal_entry_id' => $entry->getKey()])->save();
            $payment->allocations()->create(['invoice_id' => $invoice->getKey(), 'amount' => $tender->amount]);
            $tender->forceFill(['payment_id' => $payment->getKey()])->save();

            $paid = $paid->plus($tender->amount);
        }

        $invoice->forceFill([
            'paid_total' => $paid->toString(),
            'status' => $paid->lessThan(Decimal::of($invoice->total)) ? 'partially_paid' : 'paid',
        ])->save();
    }

    /**
     * The Finance contact this sale invoices.
     *
     * A POS sales contact maps to a finance contact; when the sale is a
     * walk-in, a single shared "Walk-in customer" is created once per company
     * and reused, so retail revenue is not spread across thousands of
     * throwaway contacts.
     */
    private function resolveInvoiceContactId(PosSale $sale): int
    {
        $companyId = (int) $sale->company_id;

        if ($sale->sales_contact_id) {
            $salesContact = DB::table('sales_contacts')
                ->where('company_id', $companyId)
                ->where('id', $sale->sales_contact_id)
                ->first();

            if ($salesContact?->finance_contact_id) {
                $financeContactId = DB::table('finance_contacts')
                    ->where('company_id', $companyId)
                    ->whereIn('type', ['customer', 'both'])
                    ->where('id', $salesContact->finance_contact_id)
                    ->value('id');

                if ($financeContactId) {
                    return (int) $financeContactId;
                }
            }

            if ($salesContact) {
                $financeContactId = $this->financeContactId($companyId, $salesContact->name);
                DB::table('sales_contacts')
                    ->where('company_id', $companyId)
                    ->where('id', $salesContact->id)
                    ->update(['finance_contact_id' => $financeContactId, 'updated_at' => now()]);

                return $financeContactId;
            }
        }

        return $this->financeContactId($companyId, 'Walk-in customer');
    }

    private function financeContactId(int $companyId, string $name): int
    {
        $existing = DB::table('finance_contacts')
            ->where('company_id', $companyId)
            ->whereIn('type', ['customer', 'both'])
            ->where('name', $name)
            ->value('id');

        if ($existing) {
            return (int) $existing;
        }

        return (int) DB::table('finance_contacts')->insertGetId([
            'company_id' => $companyId,
            'type' => 'customer',
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Receipt numbers run per register, so two tills never print the same one.
     */
    public function assignReceiptNumber(PosSale $sale, PosRegister $register): void
    {
        $number = $this->numbers->next(
            (int) $sale->company_id,
            $register->receipt_prefix,
            $sale->business_date->format('Y'),
            'pos-register:'.$register->getKey(),
        );

        $sale->forceFill(['receipt_number' => $number])->save();
    }
}
