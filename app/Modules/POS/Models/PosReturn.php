<?php

namespace App\Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A reversal of part or all of a sale. */
class PosReturn extends Model
{
    protected $fillable = [
        'uuid',
        'company_id',
        'pos_sale_id',
        'pos_register_id',
        'pos_shift_id',
        'cashier_id',
        'approved_by_user_id',
        'status',
        'receipt_number',
        'business_date',
        'subtotal',
        'tax_total',
        'total',
        'cost_total',
        'reason',
        'credit_note_id',
        'stock_movement_id',
        'cogs_journal_entry_id',
    ];

    protected function casts(): array
    {
        return ['business_date' => 'date'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PosReturnLine::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PosRefund::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }
}
