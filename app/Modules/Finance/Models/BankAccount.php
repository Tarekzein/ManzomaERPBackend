<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    protected $guarded = [];

    public function transactions()
    {
        return $this->hasMany(BankTransaction::class);
    }
}
