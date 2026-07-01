<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceContact extends Model
{
    protected $guarded = [];

    protected $appends = ['balance'];

    protected function casts(): array
    {
        return ['address' => 'array'];
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'contact_id');
    }

    public function getBalanceAttribute(): float
    {
        return round((float) $this->invoices()
            ->whereIn('status', ['posted', 'partially_paid'])
            ->selectRaw('COALESCE(SUM(total - paid_total - COALESCE(credited_total, 0)), 0) balance')
            ->value('balance'), 4);
    }
}
