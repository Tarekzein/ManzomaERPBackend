<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

class SalesQuotation extends Model
{
    protected $table = 'sales_quotations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quote_date' => 'date', 'valid_until' => 'date'];
    }

    public function customer()
    {
        return $this->belongsTo(SalesContact::class, 'customer_id');
    }

    public function lines()
    {
        return $this->morphMany(SalesOrderLine::class, 'document');
    }
}
