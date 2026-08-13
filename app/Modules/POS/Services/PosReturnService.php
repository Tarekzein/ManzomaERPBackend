<?php

namespace App\Modules\POS\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\AccountingPostingService;
use App\Modules\Finance\Services\CompanyAccountResolver;
use App\Modules\Finance\Services\LedgerService;
use App\Modules\Inventory\Models\InventoryCostLayer;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Platform\Services\DocumentNumberService;
use App\Modules\Platform\Services\IdempotencyService;
use App\Modules\POS\Models\PosOutboxEvent;
use App\Modules\POS\Models\PosRefund;
use App\Modules\POS\Models\PosRegister;
use App\Modules\POS\Models\PosRegisterPaymentMethod;
use App\Modules\POS\Models\PosReturn;
use App\Modules\POS\Models\PosReturnLine;
use App\Modules\POS\Models\PosSale;
use App\Modules\POS\Models\PosSaleLine;
use App\Modules\POS\Models\PosShift;
use App\Modules\POS\Models\PosTender;
use App\Modules\POS\Policies\PosPolicy;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Returns, refunds and voids.
 *
 * A finalized sale is never edited or deleted. Money and stock come back
 * through a return, which is its own document with its own reversing postings,
 * so the audit trail keeps both the original sale and its correction.
 *
 * The invariant that matters most: cumulative returned quantity per sale line
 * can never exceed what was sold. That is enforced under a row lock, because
 * two supervisors refunding the same receipt at two tills would otherwise each
 * see the same remaining quantity.
 */
class PosReturnService
{
    public function __construct(
        private readonly PosPolicy $policy,
        private readonly DocumentNumberService $numbers,
        private readonly CompanyAccountResolver $accounts,
        private readonly LedgerService $ledger,
        private readonly AccountingPostingService $posting,
        private readonly IdempotencyService $idempotency,
        private readonly PosAccountResolver $posAccounts,
        private readonly PosShiftService $shifts,
    ) {}

    /** @return array{return: PosReturn, replayed: bool} */
    public function returnSale(User $user, PosSale $sale, array $data): array
    {
        $companyId = $this->policy->companyId($user, 'pos.return');
        abort_unless((int) $sale->company_id === $companyId, 404);

        // A return moves money out, so it always carries a supervisor.
        $supervisor = $this->policy->resolveSupervisor($user, $data['supervisor_id'] ?? null);
        $register = $this->policy->register($user, (int) ($data['pos_register_id'] ?? $sale->pos_register_id));

        $outcome = $this->idempotency->execute(
            $companyId,
            'pos.return',
            $data['idempotency_key'],
            ['sale' => $sale->getKey(), 'command' => collect($data)->except('idempotency_key')->all()],
            function () use ($user, $supervisor, $sale, $register, $data) {
                $shift = $this->policy->openShift($user, $register);

                return $this->finalize($user, $supervisor, $sale, $register, $shift->getKey(), $data);
            },
            fn (PosReturn $return) => ['return_id' => $return->getKey(), 'uuid' => $return->uuid],
            $user->getKey(),
        );

        $return = $outcome['result'] ?? PosReturn::query()->findOrFail($outcome['response']['return_id']);

        return [
            'return' => $return->load(['lines', 'refunds']),
            'replayed' => $outcome['replayed'],
        ];
    }

    private function finalize(
        User $user,
        User $supervisor,
        PosSale $sale,
        $register,
        int $shiftId,
        array $data,
        bool $void = false,
    ): PosReturn {
        return DB::transaction(function () use ($user, $supervisor, $sale, $register, $shiftId, $data, $void) {
            $companyId = (int) $sale->company_id;

            // Match checkout's register -> shift -> document/stock lock order.
            PosRegister::query()->whereKey($register->getKey())->lockForUpdate()->firstOrFail();
            $lockedShift = PosShift::query()->whereKey($shiftId)->lockForUpdate()->firstOrFail();
            if (! $lockedShift->isOpen()) {
                throw ValidationException::withMessages(['shift' => ['This shift was closed. Open a new one.']]);
            }

            // Lock the sale and its lines: the returnable quantity is derived
            // from them and must not move while this return is priced.
            $lockedSale = PosSale::query()->whereKey($sale->getKey())->lockForUpdate()->firstOrFail();
            if (! $lockedSale->isCompleted()) {
                throw ValidationException::withMessages([
                    'sale' => ['Only a completed sale can be returned or voided.'],
                ]);
            }
            $sale = $lockedSale;
            $saleLines = PosSaleLine::query()
                ->where('pos_sale_id', $sale->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $priced = $this->priceReturn($saleLines, $data['lines']);

            $return = PosReturn::query()->create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'pos_sale_id' => $sale->getKey(),
                'pos_register_id' => $register->getKey(),
                'pos_shift_id' => $shiftId,
                'cashier_id' => $user->getKey(),
                'approved_by_user_id' => $supervisor->getKey(),
                'status' => 'completed',
                'business_date' => $this->policy->businessDate($user),
                'subtotal' => $priced['subtotal']->toString(),
                'tax_total' => $priced['tax_total']->toString(),
                'total' => $priced['total']->toString(),
                'cost_total' => $priced['cost_total']->toString(),
                'reason' => $data['reason'] ?? null,
            ]);

            foreach ($priced['lines'] as $line) {
                $return->lines()->create($line + ['company_id' => $companyId]);
            }

            // Consume the returnable quantity now that it is recorded.
            foreach ($priced['lines'] as $line) {
                $saleLine = $saleLines[$line['pos_sale_line_id']];
                $saleLine->forceFill([
                    'returned_quantity' => Decimal::of($saleLine->returned_quantity)
                        ->plus($line['quantity'])
                        ->toString(),
                ])->save();
            }

            $this->restock($user, $sale, $return, $register);
            $this->postCreditNote($user, $sale, $return);
            $this->refundTenders($user, $supervisor, $sale, $return, $register, $data['refunds'] ?? null);

            $return->forceFill([
                'receipt_number' => $this->numbers->next(
                    $companyId,
                    $register->receipt_prefix.'-RTN',
                    $return->business_date->format('Y'),
                    'pos-register:'.$register->getKey(),
                ),
            ])->save();

            $saleUpdates = [
                'returned_total' => Decimal::of($sale->returned_total)->plus($priced['total'])->toString(),
            ];
            if ($void) {
                $saleUpdates += [
                    'status' => PosSale::STATUS_VOIDED,
                    'voided_by_user_id' => $user->getKey(),
                    'voided_at' => now(),
                ];
            }
            $sale->forceFill($saleUpdates)->save();

            $this->shifts->refreshLiveTotals($lockedShift);

            PosOutboxEvent::query()->create([
                'company_id' => $companyId,
                'event' => 'pos.return.completed',
                'subject_type' => PosReturn::class,
                'subject_id' => $return->getKey(),
                'payload' => ['uuid' => $return->uuid, 'total' => $return->total],
                'available_at' => now(),
            ]);

            return $return->refresh();
        });
    }

    /**
     * Price the return from the *original* line snapshots.
     *
     * Never from the current catalogue: a customer is refunded what they paid,
     * including the discount they received, not today's shelf price.
     *
     * @param  Collection<int, PosSaleLine>  $saleLines
     */
    private function priceReturn($saleLines, array $requested): array
    {
        $lines = [];
        $seenLineIds = [];
        $subtotal = Decimal::zero();
        $taxTotal = Decimal::zero();
        $costTotal = Decimal::zero();
        $alreadyReturned = $this->returnedAmounts($saleLines);

        foreach ($requested as $request) {
            $saleLineId = (int) $request['pos_sale_line_id'];
            if (isset($seenLineIds[$saleLineId])) {
                throw ValidationException::withMessages([
                    'lines' => ['Each sale line may appear only once in a return. Combine its quantity into one row.'],
                ]);
            }
            $seenLineIds[$saleLineId] = true;

            $saleLine = $saleLines[$saleLineId]
                ?? throw ValidationException::withMessages([
                    'lines' => ['That line does not belong to this sale.'],
                ]);

            $quantity = Decimal::of($request['quantity']);
            if (! $quantity->isPositive()) {
                throw ValidationException::withMessages(['lines' => ['Return quantities must be greater than zero.']]);
            }

            $remaining = Decimal::of($saleLine->returnableQuantity());
            if ($quantity->greaterThan($remaining)) {
                throw ValidationException::withMessages([
                    'lines' => ["Only {$remaining->round()} of {$saleLine->product_name} can still be returned."],
                ]);
            }

            $sold = Decimal::of($saleLine->quantity);
            $share = $quantity->dividedBy($sold);
            $prior = $alreadyReturned->get($saleLineId, [
                'net' => Decimal::zero(),
                'tax' => Decimal::zero(),
                'cost' => Decimal::zero(),
            ]);
            $remainingNet = Decimal::of($saleLine->line_subtotal)->minus($prior['net']);
            $remainingTax = Decimal::of($saleLine->tax_amount)->minus($prior['tax']);
            $remainingCost = Decimal::of($saleLine->cost_total)->minus($prior['cost']);

            if ($remainingNet->isNegative() || $remainingTax->isNegative() || $remainingCost->isNegative()) {
                throw ValidationException::withMessages([
                    'lines' => ['This sale line has inconsistent prior return amounts and must be reconciled before another return.'],
                ]);
            }

            // Proportional share of what was actually charged, so a partial
            // return of a discounted line refunds the discounted amount. The
            // final quantity absorbs every rounding residue from earlier
            // partial returns, so cumulative net, tax and cost reconcile
            // exactly to the immutable sale-line snapshot.
            if ($quantity->equals($remaining)) {
                $net = $remainingNet;
                $tax = $remainingTax;
                $cost = $remainingCost;
            } else {
                $net = $this->atMost(
                    Decimal::of($saleLine->line_subtotal)->times($share)->round(),
                    $remainingNet,
                );
                $tax = $this->atMost(
                    Decimal::of($saleLine->tax_amount)->times($share)->round(),
                    $remainingTax,
                );
                $cost = $this->atMost(
                    Decimal::of($saleLine->unit_cost)->times($quantity),
                    $remainingCost,
                );
            }

            $lines[] = [
                'pos_sale_line_id' => $saleLine->getKey(),
                'product_id' => $saleLine->product_id,
                'quantity' => $quantity->toString(),
                'unit_price' => $saleLine->unit_price,
                'tax_amount' => $tax->toString(),
                'line_total' => $net->plus($tax)->toString(),
                'unit_cost' => $saleLine->unit_cost,
                'cost_total' => $cost->toString(),
                'disposition' => $this->disposition($request['disposition'] ?? PosReturnLine::DISPOSITION_RESTOCK),
            ];

            $subtotal = $subtotal->plus($net);
            $taxTotal = $taxTotal->plus($tax);
            $costTotal = $costTotal->plus($cost);
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => ['Choose at least one line to return.']]);
        }

        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total' => $subtotal->plus($taxTotal),
            'cost_total' => $costTotal,
        ];
    }

    /**
     * Amounts consumed by completed earlier returns, keyed by sale-line id.
     *
     * The sale-line row lock held by finalize() serializes this read with any
     * competing return for the same receipt.
     *
     * @param  Collection<int, PosSaleLine>  $saleLines
     * @return Collection<int, array{net: Decimal, tax: Decimal, cost: Decimal}>
     */
    private function returnedAmounts(Collection $saleLines): Collection
    {
        return PosReturnLine::query()
            ->join('pos_returns', 'pos_returns.id', '=', 'pos_return_lines.pos_return_id')
            ->whereIn('pos_return_lines.pos_sale_line_id', $saleLines->keys())
            ->where('pos_returns.status', 'completed')
            ->get([
                'pos_return_lines.pos_sale_line_id',
                'pos_return_lines.line_total',
                'pos_return_lines.tax_amount',
                'pos_return_lines.cost_total',
            ])
            ->groupBy('pos_sale_line_id')
            ->map(fn (Collection $lines) => [
                'net' => Decimal::sum($lines->map(
                    fn (PosReturnLine $line) => Decimal::of($line->line_total)->minus($line->tax_amount),
                )),
                'tax' => Decimal::sum($lines->pluck('tax_amount')),
                'cost' => Decimal::sum($lines->pluck('cost_total')),
            ]);
    }

    private function atMost(Decimal $amount, Decimal $maximum): Decimal
    {
        return $amount->greaterThan($maximum) ? $maximum : $amount;
    }

    private function disposition(string $value): string
    {
        if (! in_array($value, PosReturnLine::DISPOSITIONS, true)) {
            throw ValidationException::withMessages(['lines' => ['Unknown return disposition.']]);
        }

        return $value;
    }

    /**
     * Put restockable goods back and reverse their cost.
     *
     * Damaged and non-restock lines are refunded but never re-enter sellable
     * stock, so COGS is only reversed for what actually came back.
     */
    private function restock(User $user, PosSale $sale, PosReturn $return, $register): void
    {
        $restockable = $return->lines->filter(fn (PosReturnLine $line) => $line->returnsToStock());

        if ($restockable->isEmpty()) {
            return;
        }

        $companyId = (int) $return->company_id;
        $movement = StockMovement::query()->create([
            'company_id' => $companyId,
            'number' => $this->numbers->next($companyId, 'SM', $return->business_date->format('Y')),
            'type' => 'receipt',
            'reason_code' => 'pos_return',
            'reference' => $return->uuid,
            'source_type' => PosReturn::class,
            'source_id' => $return->getKey(),
            'notes' => 'Point of sale return',
            'occurred_at' => now(),
            'created_by' => $user->getKey(),
        ]);

        $costTotal = Decimal::zero();

        foreach ($restockable->sortBy('product_id') as $line) {
            $quantity = Decimal::of($line->quantity);
            $unitCost = Decimal::of($line->unit_cost);

            $movement->lines()->create([
                'product_id' => $line->product_id,
                'quantity' => $quantity->toString(),
                'to_warehouse_id' => $register->warehouse_id,
                'to_location_id' => $register->location_id,
                'unit_cost' => $unitCost->toString(),
                'total_cost' => $unitCost->times($quantity)->toString(),
            ]);

            $balance = StockBalance::query()->firstOrCreate(
                [
                    'company_id' => $companyId,
                    'product_id' => $line->product_id,
                    'warehouse_id' => $register->warehouse_id,
                    'location_id' => $register->location_id,
                ],
                ['quantity' => 0, 'average_cost' => 0, 'reorder_point' => 0, 'reorder_quantity' => 0],
            );
            $locked = StockBalance::query()->whereKey($balance->getKey())->lockForUpdate()->firstOrFail();

            $oldValue = Decimal::of($locked->quantity)->times($locked->average_cost);
            $newQuantity = Decimal::of($locked->quantity)->plus($quantity);
            $locked->forceFill([
                'quantity' => $newQuantity->toString(),
                'average_cost' => $newQuantity->isPositive()
                    ? $oldValue->plus($unitCost->times($quantity))->dividedBy($newQuantity)->toString()
                    : '0',
            ])->save();

            // A returned item becomes a fresh layer at the cost it left at.
            InventoryCostLayer::query()->create([
                'company_id' => $companyId,
                'product_id' => $line->product_id,
                'warehouse_id' => $register->warehouse_id,
                'location_id' => $register->location_id,
                'original_quantity' => $quantity->toString(),
                'remaining_quantity' => $quantity->toString(),
                'unit_cost' => $unitCost->toString(),
                'received_at' => now(),
            ]);

            $costTotal = $costTotal->plus($unitCost->times($quantity));
        }

        $entry = $this->posting->postCogsReversal(
            $companyId,
            $user->getKey(),
            $return->business_date->toDateString(),
            "POS return {$return->uuid}",
            $costTotal->toFloat(),
            $sale->currency,
            PosReturn::class,
            $return->getKey(),
        );

        $return->forceFill([
            'stock_movement_id' => $movement->getKey(),
            'cogs_journal_entry_id' => $entry?->getKey(),
        ])->save();
    }

    /** Reverse the revenue and tax with a credit note against the sale's invoice. */
    private function postCreditNote(User $user, PosSale $sale, PosReturn $return): void
    {
        if (! $sale->invoice_id) {
            return;
        }

        $companyId = (int) $return->company_id;
        $revenue = $this->accounts->byCode($companyId, '4000', 'revenue', 'Sales revenue account');
        $receivable = $this->accounts->byCode($companyId, '1100', 'asset', 'Accounts receivable');
        $total = Decimal::of($return->total);

        $creditNote = CreditNote::query()->create([
            'company_id' => $companyId,
            'invoice_id' => $sale->invoice_id,
            'number' => $this->numbers->next($companyId, 'CN', $return->business_date->format('Y')),
            'credit_date' => $return->business_date->toDateString(),
            'amount' => $total->toString(),
            'reason' => $return->reason ?? 'POS return',
            'status' => 'posted',
        ]);

        $lines = [
            ['account_id' => $revenue->id, 'debit' => $return->subtotal, 'credit' => 0, 'currency' => $sale->currency, 'exchange_rate' => 1],
            ['account_id' => $receivable->id, 'debit' => 0, 'credit' => $total->toString(), 'currency' => $sale->currency, 'exchange_rate' => 1],
        ];

        if (Decimal::of($return->tax_total)->isPositive()) {
            $tax = $this->accounts->byCode($companyId, '2100', 'liability', 'Tax payable account');
            array_splice($lines, 1, 0, [[
                'account_id' => $tax->id, 'debit' => $return->tax_total, 'credit' => 0, 'currency' => $sale->currency, 'exchange_rate' => 1,
            ]]);
        }

        $entry = $this->ledger->createForCompany(
            $companyId,
            $user->getKey(),
            [
                'entry_date' => $return->business_date->toDateString(),
                'description' => "POS credit note {$creditNote->number}",
                'lines' => $lines,
            ],
            'credit_note',
            $creditNote->getKey(),
        );
        $this->ledger->postSystem($entry, $user->getKey());
        $creditNote->forceFill(['journal_entry_id' => $entry->getKey()])->save();

        // Keep the invoice's credited balance truthful, so Finance shows the
        // sale as partly reversed rather than fully paid.
        $invoice = Invoice::query()
            ->whereKey($sale->invoice_id)
            ->lockForUpdate()
            ->first();
        if ($invoice) {
            $invoice->forceFill([
                'credited_total' => Decimal::of($invoice->credited_total ?? 0)->plus($total)->toString(),
            ])->save();
        }

        $return->forceFill(['credit_note_id' => $creditNote->getKey()])->save();
    }

    /**
     * Give the money back on the tender that brought it in.
     *
     * Refunding a card to cash is how a till gets emptied, so by default each
     * tender is reversed proportionally; an explicit split is validated
     * against what each tender still has left to give.
     */
    private function refundTenders(
        User $user,
        User $supervisor,
        PosSale $sale,
        PosReturn $return,
        $register,
        ?array $requested,
    ): void {
        $this->policy->ensure($user, 'pos.refund');

        $total = Decimal::of($return->total);
        if (! $total->isPositive()) {
            return;
        }

        $tenders = PosTender::query()
            ->where('pos_sale_id', $sale->getKey())
            ->where('status', PosTender::STATUS_CAPTURED)
            ->lockForUpdate()
            ->get();

        $allocations = $requested !== null
            ? $this->explicitAllocations($tenders, $requested, $total)
            : $this->proportionalAllocations($tenders, $total);

        if (! Decimal::sum($allocations)->equals($total)) {
            throw ValidationException::withMessages([
                'refunds' => ['The original tenders do not have enough refundable value for this return.'],
            ]);
        }

        $externalTender = collect(array_keys($allocations))
            ->map(fn ($tenderId) => $tenders->firstWhere('id', $tenderId))
            ->first(fn (?PosTender $tender) => $tender
                && Decimal::of($allocations[$tender->getKey()])->isPositive()
                && $tender->tender_type !== PosRegisterPaymentMethod::TYPE_CASH);
        if ($externalTender) {
            throw ValidationException::withMessages([
                'refunds' => ["Automatic {$externalTender->tender_type} refunds are not available yet. Refund it through the provider before completing the return."],
            ]);
        }

        $methods = $register->paymentMethods()->get()->keyBy('tender_type');
        $receivable = $this->accounts->byCode((int) $return->company_id, '1100', 'asset', 'Accounts receivable');

        foreach ($allocations as $tenderId => $amount) {
            if (! $amount->isPositive()) {
                continue;
            }

            $tender = $tenders->firstWhere('id', $tenderId);
            $method = $methods->get($tender->tender_type);
            $account = $this->posAccounts->forTender(
                (int) $return->company_id,
                $method?->settlesToDrawer() ? $method?->account_id : ($method?->clearing_account_id ?? $method?->account_id),
                (bool) $method?->settlesToDrawer(),
                $sale->currency,
            );

            // Money leaving the drawer, recorded as a negative payment so the
            // tender's net contribution to the shift stays correct.
            $payment = Payment::query()->create([
                'company_id' => $return->company_id,
                'invoice_id' => $sale->invoice_id,
                'account_id' => $account->id,
                'payment_date' => $return->business_date->toDateString(),
                'amount' => $amount->negated()->toString(),
                'currency' => $sale->currency,
                'exchange_rate' => 1,
                'reference' => "POS refund {$return->uuid}",
            ]);

            $entry = $this->ledger->createForCompany(
                (int) $return->company_id,
                $user->getKey(),
                [
                    'entry_date' => $return->business_date->toDateString(),
                    'description' => "POS refund for {$return->uuid}",
                    'lines' => [
                        ['account_id' => $receivable->id, 'debit' => $amount->toString(), 'credit' => 0, 'currency' => $sale->currency, 'exchange_rate' => 1],
                        ['account_id' => $account->id, 'debit' => 0, 'credit' => $amount->toString(), 'currency' => $sale->currency, 'exchange_rate' => 1],
                    ],
                ],
                'payment',
                $payment->getKey(),
            );
            $this->ledger->postSystem($entry, $user->getKey());
            $payment->forceFill(['journal_entry_id' => $entry->getKey()])->save();
            if ($sale->invoice_id) {
                $payment->allocations()->create([
                    'invoice_id' => $sale->invoice_id,
                    'amount' => $amount->negated()->toString(),
                ]);
            }

            PosRefund::query()->create([
                'company_id' => $return->company_id,
                'pos_return_id' => $return->getKey(),
                'pos_tender_id' => $tender->getKey(),
                'tender_type' => $tender->tender_type,
                'amount' => $amount->toString(),
                'status' => PosRefund::STATUS_COMPLETED,
                'approved_by_user_id' => $supervisor->getKey(),
                'payment_id' => $payment->getKey(),
            ]);

            $tender->forceFill([
                'refunded_amount' => Decimal::of($tender->refunded_amount)->plus($amount)->toString(),
            ])->save();
        }

        // The credit note reduces the invoice total and the negative payment
        // reverses the cash allocation. Move both metadata balances together so
        // Finance continues to report a zero outstanding balance after refund.
        $invoice = Invoice::query()
            ->whereKey($sale->invoice_id)
            ->lockForUpdate()
            ->first();
        if ($invoice) {
            $paidBeforeRefund = Decimal::of($invoice->paid_total);
            if ($total->greaterThan($paidBeforeRefund)) {
                throw ValidationException::withMessages([
                    'refunds' => ['The invoice payment balance cannot cover this refund.'],
                ]);
            }

            $paid = $paidBeforeRefund->minus($total);
            $settled = Decimal::of($invoice->total)->minus($invoice->credited_total ?? 0);
            $invoice->forceFill([
                'paid_total' => $paid->toString(),
                'status' => $paid->lessThan($settled) ? 'partially_paid' : 'paid',
            ])->save();
        }
    }

    /** @return array<int, Decimal> tender id => amount */
    private function proportionalAllocations($tenders, Decimal $total): array
    {
        $refundableTotal = Decimal::sum($tenders->map(fn (PosTender $tender) => $tender->refundableAmount()));

        if (! $refundableTotal->isPositive() || $total->greaterThan($refundableTotal)) {
            throw ValidationException::withMessages([
                'refunds' => ['The original tenders do not have enough refundable value for this return.'],
            ]);
        }

        $allocations = [];
        $assigned = Decimal::zero();
        $refundableTenders = $tenders
            ->filter(fn (PosTender $tender) => Decimal::of($tender->refundableAmount())->isPositive())
            ->values();

        foreach ($refundableTenders as $tender) {
            $refundable = Decimal::of($tender->refundableAmount());
            $amount = $total->times($refundable->dividedBy($refundableTotal))->round();
            $amount = $amount->greaterThan($refundable) ? $refundable : $amount;
            $allocations[$tender->getKey()] = $amount;
            $assigned = $assigned->plus($amount);
        }

        // Currency rounding can leave a few minor units unassigned (or, with
        // several tenders, over-assigned). Rebalance against actual remaining
        // capacity so the persisted refunds always equal the return exactly.
        $remainder = $total->minus($assigned);
        if ($remainder->isPositive()) {
            foreach ($refundableTenders as $tender) {
                $id = $tender->getKey();
                $capacity = Decimal::of($tender->refundableAmount())->minus($allocations[$id]);
                $addition = $capacity->lessThan($remainder) ? $capacity : $remainder;
                $allocations[$id] = $allocations[$id]->plus($addition);
                $remainder = $remainder->minus($addition);

                if ($remainder->isZero()) {
                    break;
                }
            }
        } elseif ($remainder->isNegative()) {
            $excess = $remainder->abs();
            foreach ($refundableTenders->reverse() as $tender) {
                $id = $tender->getKey();
                $reduction = $allocations[$id]->lessThan($excess) ? $allocations[$id] : $excess;
                $allocations[$id] = $allocations[$id]->minus($reduction);
                $excess = $excess->minus($reduction);

                if ($excess->isZero()) {
                    break;
                }
            }
        }

        $assigned = Decimal::sum($allocations);

        if (! $assigned->equals($total)) {
            throw ValidationException::withMessages([
                'refunds' => ['The original tenders do not have enough refundable value for this return.'],
            ]);
        }

        return $allocations;
    }

    /** @return array<int, Decimal> */
    private function explicitAllocations($tenders, array $requested, Decimal $total): array
    {
        $allocations = [];
        $seenTenderIds = [];
        $assigned = Decimal::zero();

        foreach ($requested as $row) {
            $tenderId = (int) ($row['pos_tender_id'] ?? 0);
            if (isset($seenTenderIds[$tenderId])) {
                throw ValidationException::withMessages([
                    'refunds' => ['Each original tender may appear only once in a refund allocation.'],
                ]);
            }
            $seenTenderIds[$tenderId] = true;

            $tender = $tenders->firstWhere('id', $tenderId);

            if (! $tender) {
                throw ValidationException::withMessages([
                    'refunds' => ['That tender does not belong to this sale.'],
                ]);
            }

            $amount = Decimal::of($row['amount'] ?? 0);
            $refundable = Decimal::of($tender->refundableAmount());

            if (! $amount->isPositive() || $amount->greaterThan($refundable)) {
                throw ValidationException::withMessages([
                    'refunds' => ["Only {$refundable->round()} remains refundable on that {$tender->tender_type} tender."],
                ]);
            }

            $allocations[$tender->getKey()] = $amount;
            $assigned = $assigned->plus($amount);
        }

        if (! $assigned->equals($total)) {
            throw ValidationException::withMessages([
                'refunds' => ["Refunds total {$assigned->round()} but the return is {$total->round()}."],
            ]);
        }

        return $allocations;
    }

    /**
     * Void a completed sale: a full return of everything still returnable.
     *
     * Kept separate from delete on purpose — the sale, its invoice and its
     * stock movement all remain, with a reversing document beside them.
     *
     * @return array{return: PosReturn, replayed: bool}
     */
    public function void(User $user, PosSale $sale, array $data): array
    {
        $this->policy->ensure($user, 'pos.void');
        $companyId = $this->policy->companyId($user);
        abort_unless((int) $sale->company_id === $companyId, 404);
        $supervisor = $this->policy->resolveSupervisor($user, $data['supervisor_id'] ?? null);
        $register = $this->policy->register($user, (int) $sale->pos_register_id);

        $outcome = $this->idempotency->execute(
            $companyId,
            'pos.void',
            $data['idempotency_key'],
            ['sale' => $sale->getKey(), 'command' => collect($data)->except('idempotency_key')->all()],
            function () use ($user, $supervisor, $sale, $register, $data) {
                $shift = $this->policy->openShift($user, $register);
                $lines = $sale->lines()->get()
                    ->map(fn (PosSaleLine $line) => [
                        'pos_sale_line_id' => $line->getKey(),
                        'quantity' => $line->returnableQuantity(),
                        'disposition' => PosReturnLine::DISPOSITION_RESTOCK,
                    ])
                    ->filter(fn (array $line) => Decimal::of($line['quantity'])->isPositive())
                    ->values()
                    ->all();

                if ($lines === []) {
                    throw ValidationException::withMessages(['sale' => ['This sale has already been fully returned.']]);
                }

                return $this->finalize(
                    $user,
                    $supervisor,
                    $sale,
                    $register,
                    $shift->getKey(),
                    $data + ['reason' => 'Void', 'lines' => $lines],
                    true,
                );
            },
            fn (PosReturn $return) => ['return_id' => $return->getKey(), 'uuid' => $return->uuid],
            $user->getKey(),
        );

        $return = $outcome['result'] ?? PosReturn::query()->findOrFail($outcome['response']['return_id']);

        return [
            'return' => $return->load(['lines', 'refunds']),
            'replayed' => $outcome['replayed'],
        ];
    }
}
