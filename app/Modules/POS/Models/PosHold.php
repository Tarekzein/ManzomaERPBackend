<?php

namespace App\Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A parked cart.
 *
 * `cart` is presentation data only. Resuming a hold reprices it from scratch
 * against the current catalogue, so a cart parked before a price change cannot
 * be used to buy at yesterday's price.
 */
class PosHold extends Model
{
    protected $fillable = [
        'company_id',
        'pos_register_id',
        'cashier_id',
        'sales_contact_id',
        'label',
        'cart',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'cart' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'pos_register_id');
    }
}
