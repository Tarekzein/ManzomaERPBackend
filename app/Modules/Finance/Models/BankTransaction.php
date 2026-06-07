<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class BankTransaction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'is_reconciled' => 'boolean', 'reconciled_at' => 'datetime'];
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }
}
