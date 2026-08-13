<?php

namespace App\Modules\POS\Models;

use App\Modules\Finance\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One way a sale was paid. A split payment is several of these.
 *
 * Card data is limited to a brand and last four digits: no PAN or CVV ever
 * reaches this table, the logs or the audit trail.
 */
class PosTender extends Model
{
    public const STATUS_CAPTURED = 'captured';

    public const STATUS_PENDING = 'pending';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'company_id',
        'pos_sale_id',
        'tender_type',
        'provider',
        'amount',
        'tendered_amount',
        'change_amount',
        'refunded_amount',
        'status',
        'reference',
        'card_last4',
        'card_brand',
        'payment_id',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function refundableAmount(): string
    {
        return \App\Support\Decimal::of($this->amount)
            ->minus($this->refunded_amount)
            ->toString();
    }
}
