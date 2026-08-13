<?php

namespace App\Modules\POS\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Finance\Models\Account;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\POS\Models\PosRegister;
use App\Modules\POS\Models\PosRegisterAssignment;
use App\Modules\POS\Models\PosRegisterPaymentMethod;
use App\Modules\POS\Models\PosShift;
use App\Modules\POS\Policies\PosPolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Register administration: where a till sells from, who may staff it, and
 * which tenders it accepts.
 */
class PosRegisterService
{
    public function __construct(private readonly PosPolicy $policy) {}

    public function list(User $user): Collection
    {
        $companyId = $this->policy->companyId($user, 'pos.registers.manage');

        return PosRegister::query()
            ->where('company_id', $companyId)
            ->with(['warehouse:id,name', 'location:id,name', 'paymentMethods', 'assignments.user:id,name,email'])
            ->orderBy('name')
            ->get();
    }

    public function create(User $user, array $data): PosRegister
    {
        $companyId = $this->policy->companyId($user, 'pos.registers.manage');
        $this->assertWarehouseAndLocationBelongToCompany($companyId, $data);
        $this->assertSettingsAreSupported($data['settings'] ?? []);

        return DB::transaction(function () use ($companyId, $data) {
            $register = PosRegister::query()->create([
                'company_id' => $companyId,
                'warehouse_id' => $data['warehouse_id'],
                'location_id' => $data['location_id'] ?? null,
                'code' => $data['code'],
                'name' => $data['name'],
                'currency' => $data['currency'] ?? 'EGP',
                'receipt_prefix' => $data['receipt_prefix'] ?? 'POS',
                'is_active' => $data['is_active'] ?? true,
                'settings' => $data['settings'] ?? [],
            ]);

            // A till with no tenders cannot take money, so cash is enabled by
            // default and can be reconfigured afterwards.
            $this->syncPaymentMethods($register, $data['payment_methods'] ?? [[
                'tender_type' => PosRegisterPaymentMethod::TYPE_CASH,
                'label' => 'Cash',
            ]]);

            return $register->load('paymentMethods');
        });
    }

    public function update(User $user, PosRegister $register, array $data): PosRegister
    {
        $companyId = $this->policy->companyId($user, 'pos.registers.manage');
        abort_unless((int) $register->company_id === $companyId, 404);

        return DB::transaction(function () use ($companyId, $register, $data) {
            // Checkout and shift opening take this same lock. Configuration is
            // therefore checked against a stable register/shift state rather
            // than a stale route-model snapshot.
            $locked = PosRegister::query()
                ->where('company_id', $companyId)
                ->whereKey($register->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertWarehouseAndLocationBelongToCompany($companyId, $data, $locked);
            $this->assertSettingsAreSupported($data['settings'] ?? []);
            $this->assertOperationalConfigurationIsUnlocked($locked, $data);

            $locked->fill(array_filter([
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'location_id' => array_key_exists('location_id', $data) ? $data['location_id'] : $locked->location_id,
                'code' => $data['code'] ?? null,
                'name' => $data['name'] ?? null,
                'currency' => $data['currency'] ?? null,
                'receipt_prefix' => $data['receipt_prefix'] ?? null,
            ], fn ($value) => $value !== null));

            if (array_key_exists('is_active', $data)) {
                if (! $data['is_active'] && $this->hasOpenShift($locked)) {
                    throw ValidationException::withMessages([
                        'is_active' => ['Close the open shift before disabling this register.'],
                    ]);
                }
                $locked->is_active = (bool) $data['is_active'];
            }

            if (array_key_exists('settings', $data)) {
                $locked->settings = array_replace($locked->settings ?? [], $data['settings']);
            }

            $locked->save();

            if (array_key_exists('payment_methods', $data)) {
                $this->syncPaymentMethods($locked, $data['payment_methods']);
            }

            return $locked->refresh()->load('paymentMethods', 'assignments.user:id,name,email');
        });
    }

    public function assign(User $user, PosRegister $register, array $data): PosRegisterAssignment
    {
        $companyId = $this->policy->companyId($user, 'pos.registers.manage');
        abort_unless((int) $register->company_id === $companyId, 404);

        // Only someone who already works in this workspace can staff its till.
        $member = User::query()
            ->whereKey($data['user_id'])
            ->where(fn ($query) => $query
                ->whereHas('companyMemberships', fn ($membership) => $membership
                    ->where('company_id', $companyId)
                    ->where('status', 'active'))
                ->orWhere('company_id', $companyId))
            ->firstOr(fn () => throw ValidationException::withMessages([
                'user_id' => ['That user is not a member of this company workspace.'],
            ]));

        return PosRegisterAssignment::query()->updateOrCreate(
            [
                'pos_register_id' => $register->getKey(),
                'user_id' => $member->getKey(),
                'role' => $data['role'] ?? PosRegisterAssignment::ROLE_CASHIER,
            ],
            [
                'company_id' => $companyId,
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
            ],
        );
    }

    public function unassign(User $user, PosRegister $register, int $assignmentId): void
    {
        $companyId = $this->policy->companyId($user, 'pos.registers.manage');
        abort_unless((int) $register->company_id === $companyId, 404);

        PosRegisterAssignment::query()
            ->where('company_id', $companyId)
            ->where('pos_register_id', $register->getKey())
            ->whereKey($assignmentId)
            ->firstOrFail()
            ->delete();
    }

    /** @param  array<int, array<string, mixed>>  $methods */
    private function syncPaymentMethods(PosRegister $register, array $methods): void
    {
        $this->assertPaymentAccountsBelongToCompany((int) $register->company_id, $methods);
        $keep = [];

        foreach ($methods as $index => $method) {
            if (! PosRegisterPaymentMethod::isCheckoutSupported((string) ($method['tender_type'] ?? ''))) {
                throw ValidationException::withMessages([
                    'payment_methods' => ['Only cash and verified card payment methods are currently supported.'],
                ]);
            }

            $record = PosRegisterPaymentMethod::query()->updateOrCreate(
                [
                    'pos_register_id' => $register->getKey(),
                    'tender_type' => $method['tender_type'],
                ],
                [
                    'company_id' => $register->company_id,
                    'label' => $method['label'] ?? ucfirst($method['tender_type']),
                    'provider' => $method['provider'] ?? null,
                    'account_id' => $method['account_id'] ?? null,
                    'clearing_account_id' => $method['clearing_account_id'] ?? null,
                    'is_active' => $method['is_active'] ?? true,
                    'opens_drawer' => $method['opens_drawer'] ?? ($method['tender_type'] === PosRegisterPaymentMethod::TYPE_CASH),
                    'sort_order' => $method['sort_order'] ?? $index,
                    'settings' => $method['settings'] ?? null,
                ],
            );

            $keep[] = $record->getKey();
        }

        PosRegisterPaymentMethod::query()
            ->where('pos_register_id', $register->getKey())
            ->whereNotIn('id', $keep)
            ->delete();
    }

    private function hasOpenShift(PosRegister $register): bool
    {
        return PosShift::query()
            ->where('company_id', $register->company_id)
            ->where('pos_register_id', $register->getKey())
            ->where('status', PosShift::STATUS_OPEN)
            ->exists();
    }

    private function assertOperationalConfigurationIsUnlocked(PosRegister $register, array $data): void
    {
        $changed = [];
        $operationalFields = [
            'warehouse_id' => fn ($value) => (int) $value !== (int) $register->warehouse_id,
            'location_id' => fn ($value) => ($value === null ? null : (int) $value)
                !== ($register->location_id === null ? null : (int) $register->location_id),
            'currency' => fn ($value) => (string) $value !== (string) $register->currency,
            'receipt_prefix' => fn ($value) => (string) $value !== (string) $register->receipt_prefix,
        ];

        foreach ($operationalFields as $field => $differs) {
            if (array_key_exists($field, $data) && $differs($data[$field])) {
                $changed[] = $field;
            }
        }

        if (array_key_exists('settings', $data)
            && array_replace($register->settings ?? [], $data['settings']) !== ($register->settings ?? [])) {
            $changed[] = 'settings';
        }

        // Payment-method replacement can delete or remap rows, so even a
        // superficially similar payload is treated as an operational edit.
        if (array_key_exists('payment_methods', $data)) {
            $changed[] = 'payment_methods';
        }

        if ($changed !== [] && $this->hasOpenShift($register)) {
            throw ValidationException::withMessages([
                'register' => ['Close the open shift before changing operational register configuration: '.implode(', ', $changed).'.'],
            ]);
        }
    }

    private function assertSettingsAreSupported(array $settings): void
    {
        if ((bool) data_get($settings, 'stock.allow_negative', false)) {
            throw ValidationException::withMessages([
                'settings.stock.allow_negative' => ['Negative stock is unavailable until receipt-side valuation reconciliation is implemented.'],
            ]);
        }
    }

    private function assertWarehouseAndLocationBelongToCompany(
        int $companyId,
        array $data,
        ?PosRegister $register = null,
    ): void {
        $warehouseId = array_key_exists('warehouse_id', $data)
            ? (int) $data['warehouse_id']
            : (int) ($register?->warehouse_id ?? 0);

        if ($warehouseId === 0 || ! Warehouse::query()
            ->where('company_id', $companyId)
            ->whereKey($warehouseId)
            ->exists()) {
            throw ValidationException::withMessages([
                'warehouse_id' => ['The selected warehouse is not available in this workspace.'],
            ]);
        }

        $locationId = array_key_exists('location_id', $data)
            ? $data['location_id']
            : $register?->location_id;

        if ($locationId !== null && ! WarehouseLocation::query()
            ->where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->whereKey($locationId)
            ->exists()) {
            throw ValidationException::withMessages([
                'location_id' => ['The selected location does not belong to the selected warehouse.'],
            ]);
        }
    }

    /** @param  array<int, array<string, mixed>>  $methods */
    private function assertPaymentAccountsBelongToCompany(int $companyId, array $methods): void
    {
        $accountIds = collect($methods)
            ->flatMap(fn (array $method) => [
                $method['account_id'] ?? null,
                $method['clearing_account_id'] ?? null,
            ])
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($accountIds->isEmpty()) {
            return;
        }

        $ownedCount = Account::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $accountIds)
            ->where('type', 'asset')
            ->where('is_active', true)
            ->count();

        if ($ownedCount !== $accountIds->count()) {
            throw ValidationException::withMessages([
                'payment_methods' => ['Every payment account must be an active asset account in this workspace.'],
            ]);
        }
    }
}
