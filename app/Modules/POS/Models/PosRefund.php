<?php

namespace App\Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money going back out, against the tender that brought it in. */
class PosRefund extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id',
        'pos_return_id',
        'pos_tender_id',
        'tender_type',
        'amount',
        'status',
        'provider_reference',
        'approved_by_user_id',
        'payment_id',
    ];

    public function posReturn(): BelongsTo
    {
        return $this->belongsTo(PosReturn::class, 'pos_return_id');
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(PosTender::class, 'pos_tender_id');
    }
}
