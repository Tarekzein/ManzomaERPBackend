<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class JobPosting extends Model
{
    protected $table = 'hr_job_postings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['closes_on' => 'date'];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function applicants()
    {
        return $this->hasMany(Applicant::class);
    }
}
