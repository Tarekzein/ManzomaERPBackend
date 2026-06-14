<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeDocument extends Model
{
    protected $table = 'hr_employee_documents';

    protected $guarded = [];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function versions()
    {
        return $this->hasMany(EmployeeDocumentVersion::class, 'document_id');
    }
}
