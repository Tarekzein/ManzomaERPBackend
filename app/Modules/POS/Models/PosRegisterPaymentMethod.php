<?php

namespace App\Modules\POS\Models;

use App\Modules\Finance\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tender a register accepts, and where its money is posted.
 *
 * Card takings usually land in a clearing account until the acquirer settles,
 * which is why that is a separate mapping from the cash account.
 */
class PosRegisterPaymentMethod extends Model
{
    public const TYPE_CASH = 'cash';

    public const TYPE_CARD = 'card';

    public const TYPE_WALLET = 'wallet';

    public const TYPE_TRANSFER = 'transfer';

    public const TYPES = [self::TYPE_CASH, self::TYPE_CARD, self::TYPE_WALLET, self::TYPE_TRANSFER];

    /**
     * Tender types that have a complete, server-verifiable settlement path.
     *
     * Wallet and transfer remain reserved schema values so historical data can
     * still be read, but they must not be offered or accepted until their
     * provider confirmation workflows are implemented.
     */
    public const CHECKOUT_TYPES = [self::TYPE_CASH, self::TYPE_CARD];

    protected $fillable = [
        'company_id',
        'pos_register_id',
        'tender_type',
        'label',
        'provider',
        'account_id',
        'clearing_account_id',
        'is_active',
        'opens_drawer',
        'sort_order',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'opens_drawer' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'pos_register_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function clearingAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'clearing_account_id');
    }

    /** Cash is the only tender that settles into the drawer immediately. */
    public function settlesToDrawer(): bool
    {
        return $this->tender_type === self::TYPE_CASH;
    }

    public static function isCheckoutSupported(string $type): bool
    {
        return in_array($type, self::CHECKOUT_TYPES, true);
    }
}
