<?php

namespace App\Modules\Sales\Models;

use App\Modules\Finance\Models\FinanceContact;
use Illuminate\Database\Eloquent\Model;

class SalesContact extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['address' => 'array'];
    }

    public function financeContact()
    {
        return $this->belongsTo(FinanceContact::class);
    }
}
