<?php

namespace App\Modules\HR\Models;

use App\Modules\Authentication\Models\User;
use Illuminate\Database\Eloquent\Model;

class OnboardingTask extends Model
{
    protected $table = 'hr_onboarding_tasks';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'completed_at' => 'datetime'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
