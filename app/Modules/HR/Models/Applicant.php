<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class Applicant extends Model
{
    protected $table = 'hr_applicants';

    protected $guarded = [];

    public function jobPosting()
    {
        return $this->belongsTo(JobPosting::class);
    }
}
