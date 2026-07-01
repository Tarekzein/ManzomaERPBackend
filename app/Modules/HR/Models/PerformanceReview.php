<?php

namespace App\Modules\HR\Models;

use App\Modules\Authentication\Models\User;
use Illuminate\Database\Eloquent\Model;

class PerformanceReview extends Model
{
    protected $table = 'hr_performance_reviews';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['score' => 'decimal:2', 'goals' => 'array', 'reviewed_on' => 'date'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
