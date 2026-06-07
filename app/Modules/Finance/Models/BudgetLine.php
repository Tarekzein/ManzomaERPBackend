<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetLine extends Model
{
    protected $guarded = [];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
