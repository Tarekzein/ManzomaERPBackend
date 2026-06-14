<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    protected $table = 'hr_teams';

    protected $guarded = [];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }
}
