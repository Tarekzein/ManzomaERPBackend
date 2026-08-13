<?php

namespace App\Modules\POS\Models;

use App\Modules\Authentication\Models\User;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One cashier's session at one register.
 *
 * The shift is the unit of cash accountability: it opens with a counted float,
 * accumulates cash sales and drawer movements, and closes against a physical
 * count. Expected cash is always derived, never stored as an input, so the
 * variance means something.
 */
class PosShift extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'company_id',
        'pos_register_id',
        'cashier_id',
        'status',
        'business_date',
        'opening_float',
        'expected_cash',
        'counted_cash',
        'cash_variance',
        'counted_denominations',
        'sales_total',
        'returns_total',
        'sale_count',
        'opened_at',
        'closed_at',
        'closed_by_user_id',
        'variance_approved_by',
        'variance_approved_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'counted_denominations' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'variance_approved_at' => 'datetime',
        ];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'pos_register_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(PosCashMovement::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(PosSale::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
