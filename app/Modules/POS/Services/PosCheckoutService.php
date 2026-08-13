<?php

namespace App\Modules\POS\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Platform\Services\IdempotencyService;
use App\Modules\POS\Models\PosOutboxEvent;
use App\Modules\POS\Models\PosPaymentAttempt;
use App\Modules\POS\Models\PosRegister;
use App\Modules\POS\Models\PosRegisterPaymentMethod;
use App\Modules\POS\Models\PosSale;
use App\Modules\POS\Models\PosShift;
use App\Modules\POS\Models\PosTender;
use App\Modules\POS\Policies\PosPolicy;
use App\Modules\Sales\Models\SalesContact;
use App\Support\Decimal;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The single backend operation behind a completed sale.
 *
 * The frontend never orchestrates "create order → remove stock → create invoice
 * → record payment". It posts one cart and gets one receipt, because those four
 * things must either all happen or none of them do: a sale that took the money
 * but not the stock, or issued stock without an invoice, is a reconciliation
 * problem nobody can fix from a till.
 *
 * Ordering inside the transaction is deliberate:
 *   1. claim the idempotency key    — a retry must not become a second sale
 *   2. lock register, shift, stock  — in a fixed order, so baskets queue
 *   3. reprice on the server        — the client's numbers are only a request
 *   4. validate tenders and stock   — before anything is written
 *   5. write sale, stock, invoice, payments, receipt number
 *   6. queue side effects to outbox — dispatched only after commit
 */
class PosCheckoutService
{
    public function __construct(
        private readonly PosPolicy $policy,
        private readonly PosPricingService $pricing,
        private readonly PosLedgerService $ledger,
        private readonly PosTerminalService $terminals,
        private readonly IdempotencyService $idempotency,
    ) {}

    /** @return array{sale: PosSale, replayed: bool} */
    public function checkout(User $user, array $data): array
    {
        $companyId = $this->policy->companyId($user, 'pos.sell');
        $register = $this->policy->register($user, (int) $data['pos_register_id']);
        $this->policy->ensureAssigned($user, $register);

        $outcome = $this->idempotency->execute(
            $companyId,
            'pos.checkout',
            $data['idempotency_key'],
            Arr::except($data, ['idempotency_key']),
            fn () => $this->finalize($user, $register, $data),
            fn (PosSale $sale) => ['sale_id' => $sale->getKey(), 'uuid' => $sale->uuid],
            $user->getKey(),
        );

        $sale = $outcome['result'] ?? PosSale::query()->findOrFail($outcome['response']['sale_id']);

        return [
            'sale' => $sale->load(['lines', 'tenders', 'register:id,name,receipt_prefix', 'cashier:id,name']),
            'replayed' => $outcome['replayed'],
        ];
    }

    private function finalize(User $user, PosRegister $register, array $data): PosSale
    {
        return DB::transaction(function () use ($user, $register, $data) {
            $companyId = (int) $register->company_id;

            // Re-read under lock: the register could have been disabled and
            // the shift closed between authorization and here.
            $lockedRegister = PosRegister::query()->whereKey($register->getKey())->lockForUpdate()->firstOrFail();
            $this->policy->ensureRegisterIsUsable($lockedRegister);

            $lockedShift = PosShift::query()
                ->where('company_id', $companyId)
                ->where('pos_register_id', $lockedRegister->getKey())
                ->where('cashier_id', $user->getKey())
                ->where('status', PosShift::STATUS_OPEN)
                ->lockForUpdate()
                ->first();

            if (! $lockedShift) {
                throw ValidationException::withMessages(['shift' => ['This shift was closed. Open a new one.']]);
            }

            $this->assertContactsBelongToCompany($companyId, $lockedRegister, $data);

            $priced = $this->pricing->price($user, $lockedRegister, $data['lines'], $data['sales_contact_id'] ?? null);

            // One deterministic pass over the stock this cart consumes.
            $balances = $this->lockStock($companyId, $lockedRegister, $priced['lines']);
            $this->assertStockAvailable($priced['lines'], $balances);

            $tenders = $this->validateTenders($lockedRegister, $priced['total'], $data['tenders'] ?? []);
            // A card tender must point at an attempt the provider actually
            // authorised. Claimed here, inside the transaction, so the same
            // authorisation cannot settle two baskets.
            $attempts = $this->claimCardAttempts($companyId, $lockedRegister->getKey(), $tenders['rows']);
            $tenderRows = $this->withVerifiedCardMetadata($tenders['rows'], $attempts);

            $sale = PosSale::query()->create([
                'uuid' => $data['uuid'] ?? (string) Str::uuid(),
                'company_id' => $companyId,
                'pos_register_id' => $lockedRegister->getKey(),
                'pos_shift_id' => $lockedShift->getKey(),
                'cashier_id' => $user->getKey(),
                'sales_contact_id' => $data['sales_contact_id'] ?? $lockedRegister->defaultContactId(),
                'crm_contact_id' => $data['crm_contact_id'] ?? null,
                'status' => PosSale::STATUS_COMPLETED,
                'business_date' => $lockedShift->business_date,
                'currency' => $lockedRegister->currency,
                'subtotal' => $priced['subtotal'],
                'discount_total' => $priced['discount_total'],
                'tax_total' => $priced['tax_total'],
                'rounding_total' => $priced['rounding_total'],
                'total' => $priced['total'],
                'paid_total' => $tenders['applied']->toString(),
                'change_total' => $tenders['change']->toString(),
                'cost_total' => $priced['cost_total'],
                'note' => $data['note'] ?? null,
                'completed_at' => now(),
            ]);

            foreach ($priced['lines'] as $line) {
                $sale->lines()->create($line + ['company_id' => $companyId]);
            }

            foreach ($tenderRows as $tender) {
                $sale->tenders()->create($tender + ['company_id' => $companyId]);
            }

            // Hand off to the modules that own the numbers. Each of these
            // throws on failure, which rolls the whole sale back.
            $this->ledger->issueStock($user, $sale, $lockedRegister, $balances);
            $this->ledger->postInvoiceAndPayments($user, $sale, $lockedRegister);
            $this->ledger->assignReceiptNumber($sale, $lockedRegister);

            foreach ($attempts as $attempt) {
                $attempt->forceFill(['pos_sale_id' => $sale->getKey()])->save();
            }

            $this->recordOutbox($sale);
            $this->refreshShiftTotals($lockedShift);

            return $sale->refresh();
        });
    }

    /**
     * Lock every stock balance this cart touches, ordered.
     *
     * @return array<int, StockBalance> keyed by product id
     */
    private function lockStock(int $companyId, PosRegister $register, array $lines): array
    {
        $productIds = collect($lines)->pluck('product_id')->unique()->sort()->values();
        $locked = [];

        foreach ($productIds as $productId) {
            $balance = StockBalance::query()->firstOrCreate(
                [
                    'company_id' => $companyId,
                    'product_id' => $productId,
                    'warehouse_id' => $register->warehouse_id,
                    'location_id' => $register->location_id,
                ],
                ['quantity' => 0, 'average_cost' => 0, 'reorder_point' => 0, 'reorder_quantity' => 0],
            );

            $locked[(int) $productId] = StockBalance::query()
                ->whereKey($balance->getKey())
                ->lockForUpdate()
                ->firstOrFail();
        }

        return $locked;
    }

    /** @param  array<int, StockBalance>  $balances */
    private function assertStockAvailable(array $lines, array $balances): void
    {
        // Quantities are summed per product first: the same item scanned on
        // two lines must be checked against one balance, not twice against it.
        $required = [];
        foreach ($lines as $line) {
            $id = (int) $line['product_id'];
            $required[$id] = isset($required[$id])
                ? $required[$id]->plus($line['quantity'])
                : Decimal::of($line['quantity']);
        }

        foreach ($required as $productId => $quantity) {
            $available = Decimal::of($balances[$productId]->quantity ?? 0);

            if ($quantity->greaterThan($available)) {
                $name = Product::query()->find($productId)?->name ?? "#{$productId}";

                throw ValidationException::withMessages([
                    'lines' => ["Not enough stock for {$name}: {$available->round()} available."],
                ]);
            }
        }
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, applied: Decimal, change: Decimal}
     */
    private function validateTenders(PosRegister $register, string $total, array $tenders): array
    {
        if ($tenders === []) {
            throw ValidationException::withMessages(['tenders' => ['Take a payment before completing the sale.']]);
        }

        $accepted = $register->paymentMethods()
            ->where('is_active', true)
            ->get()
            ->keyBy('tender_type');

        $due = Decimal::of($total);
        $applied = Decimal::zero();
        $tendered = Decimal::zero();
        $rows = [];

        foreach ($tenders as $tender) {
            $type = $tender['tender_type'] ?? '';

            if (! PosRegisterPaymentMethod::isCheckoutSupported((string) $type)) {
                throw ValidationException::withMessages([
                    'tenders' => ['Only cash and verified card payments are currently supported.'],
                ]);
            }

            $method = $accepted->get($type);
            if (! $method) {
                throw ValidationException::withMessages([
                    'tenders' => ["This register does not accept {$type} payments."],
                ]);
            }

            $amount = Decimal::of($tender['amount'] ?? 0);
            if (! $amount->isPositive()) {
                throw ValidationException::withMessages(['tenders' => ['Every tender needs a positive amount.']]);
            }

            $given = isset($tender['tendered_amount'])
                ? Decimal::of($tender['tendered_amount'])
                : $amount;

            if ($type === PosRegisterPaymentMethod::TYPE_CASH && $given->lessThan($amount)) {
                throw ValidationException::withMessages([
                    'tenders' => ['Cash tendered cannot be less than the amount applied.'],
                ]);
            }

            // Non-cash methods settle exactly the amount applied.
            if ($type !== PosRegisterPaymentMethod::TYPE_CASH && ! $given->equals($amount)) {
                throw ValidationException::withMessages([
                    'tenders' => ['Non-cash tendered amount must equal the amount applied.'],
                ]);
            }

            $provider = $type === PosRegisterPaymentMethod::TYPE_CARD
                ? ($method->provider ?: 'manual_terminal')
                : $method->provider;
            $reference = isset($tender['reference']) ? trim((string) $tender['reference']) : '';

            if ($type === PosRegisterPaymentMethod::TYPE_CARD) {
                if ($reference === '') {
                    throw ValidationException::withMessages([
                        'tenders' => ['Every card payment needs a captured terminal attempt reference.'],
                    ]);
                }

                if (isset($tender['provider']) && $tender['provider'] !== $provider) {
                    throw ValidationException::withMessages([
                        'tenders' => ['That card provider is not configured for this register.'],
                    ]);
                }
            }

            $applied = $applied->plus($amount);
            $tendered = $tendered->plus($given);

            $rows[] = [
                'tender_type' => $type,
                'provider' => $provider,
                'amount' => $amount->toString(),
                'tendered_amount' => $given->toString(),
                'change_amount' => '0',
                'status' => PosTender::STATUS_CAPTURED,
                'reference' => $reference !== '' ? $reference : null,
                'card_last4' => $this->maskedTail($tender['card_last4'] ?? null),
                'card_brand' => $tender['card_brand'] ?? null,
            ];
        }

        if (! $applied->equals($due)) {
            throw ValidationException::withMessages([
                'tenders' => ["Payments total {$applied->round()} but the sale is {$due->round()}."],
            ]);
        }

        $change = $tendered->minus($applied);
        if ($change->isPositive() && $rows !== []) {
            // Change always comes out of the cash tender.
            foreach ($rows as $index => $row) {
                if ($row['tender_type'] === PosRegisterPaymentMethod::TYPE_CASH) {
                    $rows[$index]['change_amount'] = $change->toString();
                    break;
                }
            }
        }

        return ['rows' => $rows, 'applied' => $applied, 'change' => $change];
    }

    /**
     * Bind each card tender to its verified provider attempt.
     *
     * @return array<int, PosPaymentAttempt>
     */
    private function claimCardAttempts(int $companyId, int $registerId, array $tenders): array
    {
        $claimed = [];
        $references = [];

        foreach ($tenders as $tender) {
            if (($tender['tender_type'] ?? '') !== PosRegisterPaymentMethod::TYPE_CARD) {
                continue;
            }

            $reference = (string) $tender['reference'];
            if (isset($references[$reference])) {
                throw ValidationException::withMessages([
                    'tenders' => ['A terminal attempt reference can be used only once in a checkout.'],
                ]);
            }
            $references[$reference] = true;

            $claimed[] = $this->terminals->claimForCheckout(
                $companyId,
                $registerId,
                (string) $tender['provider'],
                $reference,
                (string) $tender['amount'],
            );
        }

        return $claimed;
    }

    /**
     * Card branding printed on a receipt comes from the verified attempt, not
     * from arbitrary checkout JSON. The request may still carry legacy fields,
     * but they are overwritten before a tender is persisted.
     *
     * @param  array<int, array<string, mixed>>  $tenders
     * @param  array<int, PosPaymentAttempt>  $attempts
     * @return array<int, array<string, mixed>>
     */
    private function withVerifiedCardMetadata(array $tenders, array $attempts): array
    {
        $byReference = collect($attempts)->keyBy('external_reference');

        return array_map(function (array $tender) use ($byReference) {
            if (($tender['tender_type'] ?? '') !== PosRegisterPaymentMethod::TYPE_CARD) {
                return $tender;
            }

            $response = (array) $byReference->get($tender['reference'])?->provider_response;
            $tender['card_last4'] = $this->maskedTail($response['last4'] ?? null);
            $tender['card_brand'] = $response['card_brand'] ?? $response['scheme'] ?? null;

            return $tender;
        }, $tenders);
    }

    private function assertContactsBelongToCompany(int $companyId, PosRegister $register, array $data): void
    {
        $salesContactId = $data['sales_contact_id'] ?? $register->defaultContactId();

        if ($salesContactId !== null && ! SalesContact::query()
            ->where('company_id', $companyId)
            ->whereIn('type', ['customer', 'both'])
            ->whereKey($salesContactId)
            ->exists()) {
            throw ValidationException::withMessages([
                'sales_contact_id' => ['The selected sales contact is not available in this workspace.'],
            ]);
        }

        $crmContactId = $data['crm_contact_id'] ?? null;
        if ($crmContactId !== null && ! CRMContact::query()
            ->where('company_id', $companyId)
            ->whereKey($crmContactId)
            ->exists()) {
            throw ValidationException::withMessages([
                'crm_contact_id' => ['The selected CRM contact is not available in this workspace.'],
            ]);
        }
    }

    /** Never store more than the last four digits, whatever the client sends. */
    private function maskedTail(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';

        return $digits === '' ? null : substr($digits, -4);
    }

    private function recordOutbox(PosSale $sale): void
    {
        PosOutboxEvent::query()->create([
            'company_id' => $sale->company_id,
            'event' => 'pos.sale.completed',
            'subject_type' => PosSale::class,
            'subject_id' => $sale->getKey(),
            'payload' => ['uuid' => $sale->uuid, 'total' => $sale->total],
            'available_at' => now(),
        ]);
    }

    private function refreshShiftTotals(PosShift $shift): void
    {
        $totals = DB::table('pos_sales')
            ->where('pos_shift_id', $shift->getKey())
            ->where('status', PosSale::STATUS_COMPLETED)
            ->selectRaw('COALESCE(SUM(total), 0) AS sales_total, COUNT(*) AS sale_count')
            ->first();

        $shift->forceFill([
            'sales_total' => (string) $totals->sales_total,
            'sale_count' => (int) $totals->sale_count,
        ])->save();
    }
}
