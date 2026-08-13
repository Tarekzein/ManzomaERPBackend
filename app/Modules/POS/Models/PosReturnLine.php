<?php

namespace App\Modules\POS\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosReturnLine extends Model
{
    /** Goes back on the shelf. */
    public const DISPOSITION_RESTOCK = 'restock';

    /** Comes back but is not sellable. */
    public const DISPOSITION_DAMAGED = 'damaged';

    /** Never physically returned; refund only. */
    public const DISPOSITION_NON_RESTOCK = 'non_restock';

    public const DISPOSITIONS = [
        self::DISPOSITION_RESTOCK,
        self::DISPOSITION_DAMAGED,
        self::DISPOSITION_NON_RESTOCK,
    ];

    protected $fillable = [
        'company_id',
        'pos_return_id',
        'pos_sale_line_id',
        'product_id',
        'quantity',
        'unit_price',
        'tax_amount',
        'line_total',
        'unit_cost',
        'cost_total',
        'disposition',
    ];

    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(PosSaleLine::class, 'pos_sale_line_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Only a restock puts sellable stock back. */
    public function returnsToStock(): bool
    {
        return $this->disposition === self::DISPOSITION_RESTOCK;
    }
}
