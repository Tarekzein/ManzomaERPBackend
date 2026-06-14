<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeDocumentVersion extends Model
{
    protected $table = 'hr_employee_document_versions';

    protected $guarded = [];

    public function document()
    {
        return $this->belongsTo(EmployeeDocument::class);
    }
}
