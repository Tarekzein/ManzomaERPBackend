<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'allow_manual_entries' => 'boolean'];
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }
}
