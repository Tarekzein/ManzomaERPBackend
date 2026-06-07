<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'posted_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }

    public function period()
    {
        return $this->belongsTo(FinancialPeriod::class, 'financial_period_id');
    }
}
