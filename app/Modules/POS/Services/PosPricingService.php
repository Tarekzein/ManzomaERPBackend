<?php

namespace App\Modules\POS\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\POS\Models\PosRegister;
use App\Modules\POS\Policies\PosPolicy;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Server-authoritative pricing.
 *
 * The cart the client sends is a *request*, never a quote: it names products,
 * quantities and any requested discount, and this service decides what they
 * actually cost. A browser can be edited; a receipt and its invoice cannot be
 * allowed to disagree with the catalogue because someone changed a number in
 * devtools.
 *
 * Every figure is computed with Decimal, and the same method is used both to
 * preview a cart (`POST /api/pos/price`) and to price the real checkout, so the
 * total a cashier reads is the total that posts.
 */
class PosPricingService
{
    public function __construct(private readonly PosPolicy $policy) {}

    /**
     * @param  array<int, array{product_id: int, quantity: mixed, discount_percent?: mixed, discount_amount?: mixed, unit_price?: mixed}>  $lines
     * @return array{lines: array<int, array<string, mixed>>, subtotal: string, discount_total: string, tax_total: string, rounding_total: string, total: string, cost_total: string}
     */
    public function price(User $user, PosRegister $register, array $lines, ?int $salesContactId = null): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => ['Add at least one item to the cart.']]);
        }

        $companyId = (int) $register->company_id;
        $products = $this->products($companyId, $lines);
        $priceList = $this->priceListItems($companyId, $salesContactId, $this->policy->businessDate($user));
        $taxRate = $this->defaultTaxRate($register);

        $priced = [];
        $subtotal = Decimal::zero();
        $discountTotal = Decimal::zero();
        $taxTotal = Decimal::zero();
        $costTotal = Decimal::zero();

        foreach (array_values($lines) as $index => $line) {
            $product = $products[(int) $line['product_id']]
                ?? throw ValidationException::withMessages([
                    'lines' => ['A product in this cart is not available in this workspace.'],
                ]);

            $quantity = Decimal::of($line['quantity'] ?? 0);
            if (! $quantity->isPositive()) {
                throw ValidationException::withMessages(['lines' => ['Every line needs a quantity greater than zero.']]);
            }

            $unitPrice = $this->unitPrice($user, $product, $priceList, $line);
            $gross = $unitPrice->times($quantity);
            $discount = $this->lineDiscount($user, $gross, $line);
            $net = $gross->minus($discount);

            // Tax is server-authoritative. A browser may display the rate but
            // cannot select or replace the rate that reaches the ledger.
            $rate = Decimal::of($taxRate);
            $tax = $net->percentage($rate)->round();
            $unitCost = Decimal::of($product->purchase_price);

            $priced[] = [
                'product_id' => $product->id,
                'line_number' => $index + 1,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'quantity' => $quantity->toString(),
                'unit_price' => $unitPrice->toString(),
                'discount_amount' => $discount->toString(),
                'tax_rate' => $rate->toString(),
                'tax_amount' => $tax->toString(),
                'line_subtotal' => $net->toString(),
                'line_total' => $net->plus($tax)->toString(),
                'unit_cost' => $unitCost->toString(),
                'cost_total' => $unitCost->times($quantity)->toString(),
            ];

            $subtotal = $subtotal->plus($gross);
            $discountTotal = $discountTotal->plus($discount);
            $taxTotal = $taxTotal->plus($tax);
            $costTotal = $costTotal->plus($unitCost->times($quantity));
        }

        $net = $subtotal->minus($discountTotal)->plus($taxTotal);
        $rounded = $this->applyRounding($register, $net);

        return [
            'lines' => $priced,
            'subtotal' => $subtotal->toString(),
            'discount_total' => $discountTotal->toString(),
            'tax_total' => $taxTotal->toString(),
            'rounding_total' => $rounded->minus($net)->toString(),
            'total' => $rounded->toString(),
            'cost_total' => $costTotal->toString(),
        ];
    }

    /**
     * The price of one unit.
     *
     * Order of authority: a customer price list beats the catalogue, and an
     * operator override beats both — but only for someone holding
     * pos.price_override, which is why the actor is passed in.
     */
    private function unitPrice(User $user, Product $product, array $priceList, array $line): Decimal
    {
        if (isset($line['unit_price'])) {
            $this->policy->ensure($user, 'pos.price_override');

            $override = Decimal::of($line['unit_price']);
            if ($override->isNegative()) {
                throw ValidationException::withMessages(['lines' => ['An overridden price cannot be negative.']]);
            }

            return $override;
        }

        if (isset($priceList[$product->id])) {
            $item = $priceList[$product->id];
            $price = Decimal::of($item->unit_price);

            return $item->discount_percent
                ? $price->minus($price->percentage($item->discount_percent))
                : $price;
        }

        return Decimal::of($product->sale_price);
    }

    private function lineDiscount(User $user, Decimal $gross, array $line): Decimal
    {
        $hasDiscount = isset($line['discount_percent']) || isset($line['discount_amount']);

        if (! $hasDiscount) {
            return Decimal::zero();
        }

        $this->policy->ensure($user, 'pos.discount');

        $discount = isset($line['discount_percent'])
            ? $gross->percentage($line['discount_percent'])->round()
            : Decimal::of($line['discount_amount']);

        if ($discount->isNegative()) {
            throw ValidationException::withMessages(['lines' => ['A discount cannot be negative.']]);
        }

        // A discount larger than the line would make the sale pay the customer.
        if ($discount->greaterThan($gross)) {
            throw ValidationException::withMessages(['lines' => ['A discount cannot exceed the line total.']]);
        }

        return $discount;
    }

    /** @return array<int, Product> */
    private function products(int $companyId, array $lines): array
    {
        $ids = collect($lines)->pluck('product_id')->filter()->unique()->values();

        return Product::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id')
            ->all();
    }

    /** @return array<int, object> product id => price list item */
    private function priceListItems(int $companyId, ?int $salesContactId, string $businessDate): array
    {
        if ($salesContactId === null) {
            return [];
        }

        return DB::table('sales_price_list_items')
            ->join('sales_price_lists', 'sales_price_lists.id', '=', 'sales_price_list_items.price_list_id')
            ->where('sales_price_lists.company_id', $companyId)
            ->where('sales_price_lists.contact_id', $salesContactId)
            ->where('sales_price_lists.is_active', true)
            ->where(fn ($query) => $query->whereNull('sales_price_lists.starts_on')->orWhere('sales_price_lists.starts_on', '<=', $businessDate))
            ->where(fn ($query) => $query->whereNull('sales_price_lists.ends_on')->orWhere('sales_price_lists.ends_on', '>=', $businessDate))
            ->select('sales_price_list_items.*')
            ->get()
            ->keyBy('product_id')
            ->all();
    }

    private function defaultTaxRate(PosRegister $register): string
    {
        return (string) data_get($register->settings, 'tax.default_rate', '0');
    }

    /**
     * Cash rounding, where the smallest coin is larger than the smallest unit
     * of account. Disabled by default; a register opts in via settings.
     */
    private function applyRounding(PosRegister $register, Decimal $total): Decimal
    {
        $increment = Decimal::of(data_get($register->settings, 'rounding.increment', '0'));

        if (! $increment->isPositive()) {
            return $total->round();
        }

        $steps = $total->dividedBy($increment)->round(0);

        return $steps->times($increment)->round();
    }
}
