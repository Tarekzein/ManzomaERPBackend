<?php

namespace App\Modules\POS\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\POS\Models\PosHold;
use App\Modules\POS\Policies\PosPolicy;
use App\Modules\Sales\Models\SalesContact;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Parked carts.
 *
 * A hold stores what was in the basket, never what it cost. Resuming one
 * reprices every line from scratch, so a cart parked before a price change or
 * a stock movement cannot be used to buy at yesterday's numbers.
 */
class PosHoldService
{
    public function __construct(private readonly PosPolicy $policy) {}

    public function list(User $user, int $registerId): Collection
    {
        $companyId = $this->policy->companyId($user, 'pos.hold');
        $register = $this->policy->register($user, $registerId);
        $this->policy->ensureAssigned($user, $register);

        return PosHold::query()
            ->where('company_id', $companyId)
            ->where('pos_register_id', $register->getKey())
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()
            ->get();
    }

    public function store(User $user, array $data): PosHold
    {
        $companyId = $this->policy->companyId($user, 'pos.hold');
        $register = $this->policy->register($user, (int) $data['pos_register_id']);
        $this->policy->ensureAssigned($user, $register);

        $salesContactId = $data['sales_contact_id'] ?? null;
        if ($salesContactId !== null && ! SalesContact::query()
            ->where('company_id', $companyId)
            ->whereKey($salesContactId)
            ->exists()) {
            throw ValidationException::withMessages([
                'sales_contact_id' => ['The selected sales contact is not available in this workspace.'],
            ]);
        }

        $productIds = collect($data['lines'])
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $availableProducts = Product::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereIn('id', $productIds)
            ->count();

        if ($availableProducts !== $productIds->count()) {
            throw ValidationException::withMessages([
                'lines' => ['Every held product must be active and available in this workspace.'],
            ]);
        }

        return PosHold::query()->create([
            'company_id' => $companyId,
            'pos_register_id' => $register->getKey(),
            'cashier_id' => $user->getKey(),
            'sales_contact_id' => $salesContactId,
            'label' => $data['label'] ?? null,
            // Product ids and quantities only — no prices are trusted from here.
            'cart' => collect($data['lines'])
                ->map(fn (array $line) => [
                    'product_id' => (int) $line['product_id'],
                    'quantity' => (string) $line['quantity'],
                    'discount_percent' => isset($line['discount_percent']) ? (string) $line['discount_percent'] : null,
                ])
                ->all(),
            'expires_at' => now()->addHours((int) ($data['expires_in_hours'] ?? 24)),
        ]);
    }

    public function destroy(User $user, PosHold $hold): void
    {
        $companyId = $this->policy->companyId($user, 'pos.hold');
        abort_unless((int) $hold->company_id === $companyId, 404);
        $register = $this->policy->register($user, (int) $hold->pos_register_id);
        $this->policy->ensureAssigned($user, $register);

        $hold->delete();
    }
}
