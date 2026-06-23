<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'company_id',
        'user_id',
        'subscription_plan_id',
        'billing_cycle',
        'amount',
        'currency',
        'provider',
        'status',
        'provider_order_id',
        'provider_transaction_id',
        'checkout_url',
        'registration_token_hash',
        'callback_payload',
        'metadata',
        'paid_at',
        'failed_at',
    ];

    protected $hidden = ['registration_token_hash'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'callback_payload' => 'array',
            'metadata' => 'array',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'succeeded';
    }
}
