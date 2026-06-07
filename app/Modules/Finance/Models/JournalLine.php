<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class JournalLine extends Model
{
    protected $guarded = [];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
