<?php

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

class ReportSchedule extends Model
{
    protected $fillable = ['company_id', 'report_definition_id', 'created_by', 'name', 'frequency', 'format', 'recipients', 'is_active', 'next_run_at', 'last_run_at'];

    protected function casts(): array
    {
        return ['recipients' => 'array', 'is_active' => 'boolean', 'next_run_at' => 'datetime', 'last_run_at' => 'datetime'];
    }

    public function report()
    {
        return $this->belongsTo(ReportDefinition::class, 'report_definition_id');
    }
}
