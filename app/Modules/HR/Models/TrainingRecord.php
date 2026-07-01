<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingRecord extends Model
{
    protected $table = 'hr_training_records';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_on' => 'date', 'completed_on' => 'date', 'cost' => 'decimal:2'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
