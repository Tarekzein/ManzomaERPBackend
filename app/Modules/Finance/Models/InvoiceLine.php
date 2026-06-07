<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceLine extends Model
{
    protected $guarded = [];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function taxRate()
    {
        return $this->belongsTo(TaxRate::class);
    }
}
