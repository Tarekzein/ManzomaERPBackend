<?php

namespace App\Modules\POS\Models;

use App\Modules\Authentication\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cash entering or leaving the drawer outside a sale. */
class PosCashMovement extends Model
{
    public const TYPE_PAY_IN = 'pay_in';

    public const TYPE_PAY_OUT = 'pay_out';

    public const TYPE_DRAWER_OPEN = 'drawer_open';

    public const TYPES = [self::TYPE_PAY_IN, self::TYPE_PAY_OUT, self::TYPE_DRAWER_OPEN];

    protected $fillable = [
        'company_id',
        'pos_shift_id',
        'type',
        'amount',
        'reason',
        'user_id',
        'approved_by_user_id',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class, 'pos_shift_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** A drawer-open is an audit event, not money: it never moves cash. */
    public function affectsCash(): bool
    {
        return $this->type !== self::TYPE_DRAWER_OPEN;
    }

    /** Signed contribution to expected drawer cash. */
    public function signedAmount(): string
    {
        if (! $this->affectsCash()) {
            return '0.0000';
        }

        $amount = \App\Support\Decimal::of($this->amount);

        return $this->type === self::TYPE_PAY_OUT
            ? $amount->negated()->toString()
            : $amount->toString();
    }
}
