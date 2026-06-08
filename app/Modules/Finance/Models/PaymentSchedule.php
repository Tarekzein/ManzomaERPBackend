<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentSchedule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['scheduled_for' => 'date'];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
