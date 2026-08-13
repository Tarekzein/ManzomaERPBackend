<?php

namespace App\Modules\POS\Models;

use App\Modules\Authentication\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A finalized retail sale.
 *
 * Immutable once completed: corrections are returns, refunds or a void record,
 * never an edit. The `*_id` columns are what this sale became in the modules
 * that actually own the numbers — Finance holds the invoice, Inventory the
 * stock movement — so POS is a channel, not a second ledger.
 */
class PosSale extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_VOIDED = 'voided';

    protected $fillable = [
        'uuid',
        'company_id',
        'pos_register_id',
        'pos_shift_id',
        'cashier_id',
        'sales_contact_id',
        'crm_contact_id',
        'status',
        'receipt_number',
        'business_date',
        'currency',
        'subtotal',
        'discount_total',
        'tax_total',
        'rounding_total',
        'total',
        'paid_total',
        'change_total',
        'cost_total',
        'returned_total',
        'invoice_id',
        'stock_movement_id',
        'cogs_journal_entry_id',
        'note',
        'completed_at',
        'voided_by_user_id',
        'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'completed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PosSaleLine::class);
    }

    public function tenders(): HasMany
    {
        return $this->hasMany(PosTender::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PosReturn::class);
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'pos_register_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class, 'pos_shift_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
