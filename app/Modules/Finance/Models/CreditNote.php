<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class CreditNote extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['credit_date' => 'date'];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
