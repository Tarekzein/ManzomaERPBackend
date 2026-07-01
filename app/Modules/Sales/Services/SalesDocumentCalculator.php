<?php

namespace App\Modules\Sales\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\PriceList;

class SalesDocumentCalculator
{
    public function price(int $companyId, Product $product, ?int $customerId, array $line): array
    {
        if (array_key_exists('unit_price', $line) && $line['unit_price'] !== null && $line['unit_price'] !== '') {
            return ['unit_price' => (float) $line['unit_price'], 'discount_percent' => (float) ($line['discount_percent'] ?? 0)];
        }

        $item = PriceList::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('contact_id')->orWhere('contact_id', $customerId))
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', now()))
            ->with('items')
            ->latest()
            ->get()
            ->flatMap->items
            ->firstWhere('product_id', $product->id);

        return [
            'unit_price' => (float) ($item?->unit_price ?? $product->sale_price),
            'discount_percent' => (float) ($line['discount_percent'] ?? $item?->discount_percent ?? 0),
        ];
    }

    public function totals(float $quantity, float $unitPrice, float $discountPercent = 0, float $taxPercent = 0): array
    {
        $subtotal = round($quantity * $unitPrice, 4);
        $discount = round($subtotal * $discountPercent / 100, 4);
        $taxable = max(0, $subtotal - $discount);
        $tax = round($taxable * $taxPercent / 100, 4);

        return [
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'tax_amount' => $tax,
            'total' => $taxable + $tax,
        ];
    }
}
