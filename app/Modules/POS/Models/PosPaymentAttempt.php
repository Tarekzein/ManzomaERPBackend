<?php

namespace App\Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One conversation with a card provider.
 *
 * Kept separate from pos_tenders because a sale may take several attempts —
 * declined, retried, finally approved — and every one of them is evidence.
 */
class PosPaymentAttempt extends Model
{
    public const STATE_PENDING = 'pending';

    public const STATE_CAPTURED = 'captured';

    public const STATE_FAILED = 'failed';

    protected $fillable = [
        'company_id',
        'pos_sale_id',
        'pos_register_id',
        'provider',
        'external_reference',
        'state',
        'amount',
        'attempt',
        'provider_response',
        'failure_reason',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'provider_response' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }

    /** Only a verified capture may be spent on a sale. */
    public function isSpendable(): bool
    {
        return $this->state === self::STATE_CAPTURED
            && $this->verified_at !== null
            && $this->pos_sale_id === null;
    }
}
