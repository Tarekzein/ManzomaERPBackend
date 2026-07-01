<?php

namespace App\Modules\HR\Models;

use App\Modules\Authentication\Models\User;
use Illuminate\Database\Eloquent\Model;

class DisciplinaryAction extends Model
{
    protected $table = 'hr_disciplinary_actions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['issued_on' => 'date'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
