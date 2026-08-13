<?php

namespace App\Modules\POS\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Platform\Services\IdempotencyService;
use App\Modules\POS\Models\PosCashMovement;
use App\Modules\POS\Models\PosRefund;
use App\Modules\POS\Models\PosRegister;
use App\Modules\POS\Models\PosRegisterPaymentMethod;
use App\Modules\POS\Models\PosReturn;
use App\Modules\POS\Models\PosSale;
use App\Modules\POS\Models\PosShift;
use App\Modules\POS\Models\PosTender;
use App\Modules\POS\Policies\PosPolicy;
use App\Support\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shift lifecycle and cash accountability.
 *
 * Expected cash is always recomputed from the shift's own records — float,
 * cash tenders, cash refunds and drawer movements — and never accepted from the
 * client. That is the whole point of a variance: if the expectation could be
 * supplied, a short drawer could be made to balance.
 */
class PosShiftService
{
    public function __construct(
        private readonly PosPolicy $policy,
        private readonly IdempotencyService $idempotency,
    ) {}

    public function current(User $user): ?PosShift
    {
        $companyId = $this->policy->companyId($user, 'pos.view');

        return PosShift::query()
            ->with(['register', 'cashier:id,name'])
            ->where('company_id', $companyId)
            ->where('cashier_id', $user->getKey())
            ->where('status', PosShift::STATUS_OPEN)
            ->latest('opened_at')
            ->first();
    }

    /** @return array{shift: PosShift, replayed: bool} */
    public function open(User $user, array $data): array
    {
        $companyId = $this->policy->companyId($user, 'pos.open_shift');
        $register = $this->policy->register($user, (int) $data['pos_register_id']);
        $this->policy->ensureRegisterIsUsable($register);
        $this->policy->ensureAssigned($user, $register);

        $float = Decimal::of($data['opening_float'] ?? 0);
        if ($float->isNegative()) {
            throw ValidationException::withMessages([
                'opening_float' => ['The opening float cannot be negative.'],
            ]);
        }

        $outcome = $this->idempotency->execute(
            $companyId,
            'pos.shift.open',
            $data['idempotency_key'],
            ['register' => $register->getKey(), 'float' => $float->toString()],
            fn () => DB::transaction(function () use ($companyId, $register, $user, $float) {
                // Serialize every opening attempt for this cashier before the
                // register lock. Two requests aimed at different registers
                // must not both observe that the cashier has no open shift.
                User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                PosRegister::query()->whereKey($register->getKey())->lockForUpdate()->firstOrFail();
                $this->ensureNoConflictingShift($register, $user);

                return PosShift::query()->create([
                    'company_id' => $companyId,
                    'pos_register_id' => $register->getKey(),
                    'cashier_id' => $user->getKey(),
                    'status' => PosShift::STATUS_OPEN,
                    'business_date' => $this->policy->businessDate($user),
                    'opening_float' => $float->toString(),
                    'expected_cash' => $float->toString(),
                    'opened_at' => now(),
                ]);
            }),
            fn (PosShift $shift) => ['shift_id' => $shift->getKey()],
            $user->getKey(),
        );

        $shift = $outcome['result'] ?? PosShift::query()->findOrFail($outcome['response']['shift_id']);

        return ['shift' => $shift->load('register'), 'replayed' => $outcome['replayed']];
    }

    /** @return array{movement: PosCashMovement, replayed: bool} */
    public function recordCashMovement(User $user, PosShift $shift, array $data): array
    {
        $companyId = $this->policy->companyId($user, 'pos.cash.manage');
        $this->ensureShiftBelongsToCaller($user, $shift);

        $type = $data['type'];
        $amount = Decimal::of($data['amount'] ?? 0);

        if ($type !== PosCashMovement::TYPE_DRAWER_OPEN && ! $amount->isPositive()) {
            throw ValidationException::withMessages(['amount' => ['Enter an amount greater than zero.']]);
        }

        // Taking cash out needs a second pair of eyes.
        $supervisor = $type === PosCashMovement::TYPE_PAY_OUT
            ? $this->policy->resolveSupervisor($user, $data['supervisor_id'] ?? null)
            : null;

        $outcome = $this->idempotency->execute(
            $companyId,
            'pos.shift.cash_movement',
            $data['idempotency_key'],
            ['shift' => $shift->getKey(), 'command' => collect($data)->except('idempotency_key')->all()],
            fn () => DB::transaction(function () use ($shift, $user, $type, $amount, $data, $supervisor) {
                $locked = PosShift::query()->whereKey($shift->getKey())->lockForUpdate()->firstOrFail();
                if (! $locked->isOpen()) {
                    throw ValidationException::withMessages(['shift' => ['This shift is already closed.']]);
                }

                $movement = PosCashMovement::query()->create([
                    'company_id' => $locked->company_id,
                    'pos_shift_id' => $locked->getKey(),
                    'type' => $type,
                    'amount' => $type === PosCashMovement::TYPE_DRAWER_OPEN ? '0' : $amount->toString(),
                    'reason' => $data['reason'] ?? null,
                    'user_id' => $user->getKey(),
                    'approved_by_user_id' => $supervisor?->getKey(),
                ]);

                $this->refreshLiveTotals($locked);

                return $movement;
            }),
            fn (PosCashMovement $movement) => ['movement_id' => $movement->getKey()],
            $user->getKey(),
        );

        $movement = $outcome['result'] ?? PosCashMovement::query()->findOrFail($outcome['response']['movement_id']);

        return ['movement' => $movement, 'replayed' => $outcome['replayed']];
    }

    /** @return array{shift: PosShift, replayed: bool} */
    public function close(User $user, PosShift $shift, array $data): array
    {
        $companyId = $this->policy->companyId($user, 'pos.close_shift');
        $this->ensureShiftBelongsToCaller($user, $shift);

        $counted = $this->countedTotal($data);

        $outcome = $this->idempotency->execute(
            $companyId,
            'pos.shift.close',
            $data['idempotency_key'],
            [
                'shift' => $shift->getKey(),
                'command' => collect($data)->except('idempotency_key')->all(),
                'counted' => $counted->toString(),
            ],
            fn () => DB::transaction(function () use ($shift, $user, $data, $counted) {
                $locked = PosShift::query()->whereKey($shift->getKey())->lockForUpdate()->firstOrFail();

                if (! $locked->isOpen()) {
                    throw ValidationException::withMessages(['shift' => ['This shift is already closed.']]);
                }

                $expected = $this->expectedCash($locked);
                $variance = $counted->minus($expected);
                $threshold = Decimal::of($locked->register()->firstOrFail()->varianceThreshold());

                // A drawer outside tolerance cannot be signed off by the
                // cashier who counted it.
                $approver = null;
                if ($variance->abs()->greaterThan($threshold)) {
                    $approver = $this->policy->resolveSupervisor($user, $data['supervisor_id'] ?? null);
                }

                $totals = $this->saleTotals($locked);

                $locked->forceFill([
                    'status' => PosShift::STATUS_CLOSED,
                    'expected_cash' => $expected->toString(),
                    'counted_cash' => $counted->toString(),
                    'cash_variance' => $variance->toString(),
                    'counted_denominations' => $data['denominations'] ?? null,
                    'sales_total' => $totals['sales']->toString(),
                    'returns_total' => $totals['returns']->toString(),
                    'sale_count' => $totals['count'],
                    'closed_at' => now(),
                    'closed_by_user_id' => $user->getKey(),
                    'variance_approved_by' => $approver?->getKey(),
                    'variance_approved_at' => $approver ? now() : null,
                    'notes' => $data['notes'] ?? null,
                ])->save();

                return $locked->refresh();
            }),
            fn (PosShift $closed) => ['shift_id' => $closed->getKey()],
            $user->getKey(),
        );

        $closed = $outcome['result'] ?? PosShift::query()->findOrFail($outcome['response']['shift_id']);

        return ['shift' => $closed->load('register'), 'replayed' => $outcome['replayed']];
    }

    /**
     * Cash that should be in the drawer right now.
     *
     * float + cash taken in − change given out + pay-ins − pay-outs.
     * Card and wallet tenders never touch the drawer, so they are excluded.
     */
    public function expectedCash(PosShift $shift): Decimal
    {
        // Cash received belongs to the shift that completed the original sale.
        // A later refund belongs to the shift whose drawer actually paid it.
        $cashTaken = DB::table('pos_tenders')
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_tenders.pos_sale_id')
            ->where('pos_tenders.company_id', $shift->company_id)
            ->where('pos_sales.pos_shift_id', $shift->getKey())
            ->whereIn('pos_sales.status', [PosSale::STATUS_COMPLETED, PosSale::STATUS_VOIDED])
            ->where('pos_tenders.tender_type', PosRegisterPaymentMethod::TYPE_CASH)
            ->where('pos_tenders.status', PosTender::STATUS_CAPTURED)
            ->sum('pos_tenders.amount');

        $cashRefunded = DB::table('pos_refunds')
            ->join('pos_returns', 'pos_returns.id', '=', 'pos_refunds.pos_return_id')
            ->where('pos_refunds.company_id', $shift->company_id)
            ->where('pos_returns.pos_shift_id', $shift->getKey())
            ->where('pos_refunds.status', PosRefund::STATUS_COMPLETED)
            ->where('pos_refunds.tender_type', PosRegisterPaymentMethod::TYPE_CASH)
            ->sum('pos_refunds.amount');

        $movements = PosCashMovement::query()
            ->where('pos_shift_id', $shift->getKey())
            ->get()
            ->reduce(
                fn (Decimal $carry, PosCashMovement $movement) => $carry->plus($movement->signedAmount()),
                Decimal::zero(),
            );

        return Decimal::of($shift->opening_float)
            ->plus($cashTaken ?? 0)
            ->minus($cashRefunded ?? 0)
            ->plus($movements);
    }

    private function refreshExpectedCash(PosShift $shift): void
    {
        $shift->forceFill(['expected_cash' => $this->expectedCash($shift)->toString()])->save();
    }

    /** Refresh derived live totals after a sale, return or drawer mutation. */
    public function refreshLiveTotals(PosShift $shift): PosShift
    {
        return DB::transaction(function () use ($shift) {
            $locked = PosShift::query()->whereKey($shift->getKey())->lockForUpdate()->firstOrFail();
            $totals = $this->saleTotals($locked);
            $locked->forceFill([
                'expected_cash' => $this->expectedCash($locked)->toString(),
                'sales_total' => $totals['sales']->toString(),
                'returns_total' => $totals['returns']->toString(),
                'sale_count' => $totals['count'],
            ])->save();

            return $locked->refresh();
        });
    }

    /** @return array{sales: Decimal, returns: Decimal, count: int} */
    private function saleTotals(PosShift $shift): array
    {
        $sales = PosSale::query()
            ->where('pos_shift_id', $shift->getKey())
            ->whereIn('status', [PosSale::STATUS_COMPLETED, PosSale::STATUS_VOIDED]);

        $returns = PosReturn::query()
            ->where('company_id', $shift->company_id)
            ->where('pos_shift_id', $shift->getKey())
            ->where('status', 'completed')
            ->sum('total');

        return [
            'sales' => Decimal::of((string) $sales->clone()->sum('total')),
            'returns' => Decimal::of((string) $returns),
            'count' => $sales->clone()->count(),
        ];
    }

    private function countedTotal(array $data): Decimal
    {
        if (isset($data['denominations']) && is_array($data['denominations'])) {
            // Counted by denomination: value × count, summed.
            return Decimal::sum(array_map(
                fn (array $row) => Decimal::of($row['value'] ?? 0)->times($row['count'] ?? 0),
                $data['denominations'],
            ));
        }

        return Decimal::of($data['counted_cash'] ?? 0);
    }

    private function ensureNoConflictingShift(PosRegister $register, User $user): void
    {
        $open = PosShift::query()
            ->where('status', PosShift::STATUS_OPEN)
            ->where(fn ($query) => $query
                ->where('pos_register_id', $register->getKey())
                ->orWhere('cashier_id', $user->getKey()))
            ->exists();

        if ($open) {
            throw ValidationException::withMessages([
                'shift' => ['This register or cashier already has an open shift.'],
            ]);
        }
    }

    private function ensureShiftBelongsToCaller(User $user, PosShift $shift): void
    {
        $companyId = $this->policy->companyId($user);
        abort_unless((int) $shift->company_id === $companyId, 404);

        if ((int) $shift->cashier_id !== (int) $user->getKey()
            && ! $this->policy->can($user, 'pos.supervisor_override')) {
            throw new AuthorizationException(
                'Only the shift owner or a supervisor can act on this shift.',
            );
        }
    }
}
