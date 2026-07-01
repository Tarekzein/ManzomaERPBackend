<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'due_date' => 'date'];
    }

    public function lines()
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function contact()
    {
        return $this->belongsTo(FinanceContact::class, 'contact_id');
    }

    public function getBalanceAttribute(): float
    {
        return round((float) $this->total - (float) $this->paid_total - (float) ($this->credited_total ?? 0), 4);
    }
}
