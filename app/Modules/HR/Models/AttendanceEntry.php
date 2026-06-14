<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceEntry extends Model
{
    protected $table = 'hr_attendance_entries';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'clock_in' => 'datetime', 'clock_out' => 'datetime', 'hours' => 'decimal:2'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
