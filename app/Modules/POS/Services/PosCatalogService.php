<?php

namespace App\Modules\POS\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\POS\Models\PosRegister;
use App\Modules\POS\Models\PosRegisterPaymentMethod;
use App\Modules\POS\Policies\PosPolicy;
use App\Modules\POS\Support\PosCashierRegisterView;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The cashier's view of the catalogue.
 *
 * Never loads the whole catalogue: a scan is a single indexed lookup on
 * (company_id, barcode), and browsing is server-side paginated. A till with
 * 50,000 products has to stay responsive on a tablet.
 */
class PosCatalogService
{
    private const PAGE_SIZE = 40;

    public function __construct(private readonly PosPolicy $policy) {}

    /** One indexed hit on the unique (company_id, barcode) key. */
    public function findByBarcode(User $user, PosRegister $register, string $barcode): ?array
    {
        $companyId = $this->policy->companyId($user, 'pos.view');

        $product = Product::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('barcode', trim($barcode))
            ->first();

        return $product ? $this->present($product, $register) : null;
    }

    public function search(User $user, PosRegister $register, array $filters): LengthAwarePaginator
    {
        $companyId = $this->policy->companyId($user, 'pos.view');
        $term = trim((string) ($filters['search'] ?? ''));

        $paginator = Product::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->when($filters['category_id'] ?? null, fn ($query, $id) => $query->where('category_id', $id))
            ->when($term !== '', fn ($query) => $query->where(fn ($scoped) => $scoped
                ->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('barcode', $term)))
            ->orderBy('name')
            ->paginate(min((int) ($filters['per_page'] ?? self::PAGE_SIZE), 100));

        $stock = $this->stockFor($register, $paginator->getCollection()->pluck('id')->all());

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Product $product) => $this->present($product, $register, $stock))
        );

        return $paginator;
    }

    /** Everything the cashier screen needs to start selling. */
    public function bootstrap(User $user): array
    {
        $companyId = $this->policy->companyId($user, 'pos.view');

        $registers = PosRegister::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->with([
                'warehouse:id,name',
                'location:id,name',
                'paymentMethods' => fn ($query) => $query
                    ->where('is_active', true)
                    ->whereIn('tender_type', PosRegisterPaymentMethod::CHECKOUT_TYPES)
                    ->orderBy('sort_order'),
            ])
            ->get()
            ->filter(function (PosRegister $register) use ($user) {
                try {
                    $this->policy->ensureAssigned($user, $register);

                    return true;
                } catch (\Throwable) {
                    return false;
                }
            })
            ->values()
            ->map(fn (PosRegister $register) => PosCashierRegisterView::make($register));

        return [
            'registers' => $registers,
            'permissions' => collect([
                'pos.sell', 'pos.hold', 'pos.discount', 'pos.price_override',
                'pos.open_shift', 'pos.close_shift', 'pos.cash.manage',
                'pos.void', 'pos.return', 'pos.refund', 'pos.supervisor_override',
                'pos.registers.manage', 'pos.reports.view',
            ])->mapWithKeys(fn (string $permission) => [
                $permission => $this->policy->can($user, $permission),
            ])->all(),
        ];
    }

    /** @return array<int, string> product id => quantity on hand */
    private function stockFor(PosRegister $register, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('stock_balances')
            ->where('company_id', $register->company_id)
            ->where('warehouse_id', $register->warehouse_id)
            ->when(
                $register->location_id,
                fn ($query) => $query->where('location_id', $register->location_id),
                fn ($query) => $query->whereNull('location_id'),
            )
            ->whereIn('product_id', $productIds)
            ->pluck('quantity', 'product_id')
            ->map(fn ($quantity) => (string) $quantity)
            ->all();
    }

    private function present(Product $product, PosRegister $register, ?array $stock = null): array
    {
        $stock ??= $this->stockFor($register, [$product->id]);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'unit_price' => (string) $product->sale_price,
            'category_id' => $product->category_id,
            'available' => $stock[$product->id] ?? '0.0000',
        ];
    }
}
