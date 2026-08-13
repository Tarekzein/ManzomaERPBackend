<?php

namespace App\Modules\POS\Models;

use App\Modules\Authentication\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who may staff a register, and in what capacity. */
class PosRegisterAssignment extends Model
{
    public const ROLE_CASHIER = 'cashier';

    public const ROLE_SUPERVISOR = 'supervisor';

    protected $fillable = [
        'company_id',
        'pos_register_id',
        'user_id',
        'role',
        'starts_on',
        'ends_on',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'pos_register_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** An assignment outside its date window grants nothing today. */
    public function isCurrent(?string $businessDate = null): bool
    {
        $today = $businessDate ?? now()->toDateString();

        return ($this->starts_on === null || $this->starts_on->toDateString() <= $today)
            && ($this->ends_on === null || $this->ends_on->toDateString() >= $today);
    }
}
