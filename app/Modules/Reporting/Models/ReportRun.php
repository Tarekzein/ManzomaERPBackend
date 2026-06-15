<?php

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

class ReportRun extends Model
{
    protected $fillable = ['company_id', 'report_definition_id', 'schedule_id', 'requested_by', 'status', 'format', 'row_count', 'meta', 'error', 'completed_at'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'completed_at' => 'datetime'];
    }
}
