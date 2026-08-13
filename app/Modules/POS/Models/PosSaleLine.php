<?php

namespace App\Modules\POS\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sold item, snapshotted.
 *
 * Name, sku, price and cost are copied rather than joined so a receipt
 * reprinted a year later shows what was actually sold, not what the catalogue
 * says today. `returned_quantity` is the running total that caps refunds.
 */
class PosSaleLine extends Model
{
    protected $fillable = [
        'company_id',
        'pos_sale_id',
        'product_id',
        'line_number',
        'product_name',
        'sku',
        'barcode',
        'quantity',
        'unit_price',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'line_subtotal',
        'line_total',
        'unit_cost',
        'cost_total',
        'returned_quantity',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** How much of this line is still returnable. */
    public function returnableQuantity(): string
    {
        return \App\Support\Decimal::of($this->quantity)
            ->minus($this->returned_quantity)
            ->toString();
    }
}
