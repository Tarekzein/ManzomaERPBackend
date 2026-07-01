<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeePersonalDetail extends Model
{
    protected $table = 'hr_employee_personal_details';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date', 'address' => 'array'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
