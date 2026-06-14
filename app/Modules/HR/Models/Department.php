<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $table = 'hr_departments';

    protected $guarded = [];

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function teams()
    {
        return $this->hasMany(Team::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }
}
