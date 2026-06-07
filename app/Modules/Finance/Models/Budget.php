<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class Budget extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function lines()
    {
        return $this->hasMany(BudgetLine::class);
    }
}
