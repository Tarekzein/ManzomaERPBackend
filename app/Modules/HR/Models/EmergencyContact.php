<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class EmergencyContact extends Model
{
    protected $table = 'hr_emergency_contacts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
