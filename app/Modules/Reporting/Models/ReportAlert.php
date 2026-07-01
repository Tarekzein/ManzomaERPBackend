<?php

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

class ReportAlert extends Model
{
    protected $fillable = [
        'company_id', 'report_definition_id', 'created_by', 'name',
        'metric_field', 'operator', 'threshold_value', 'recipients', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'is_active' => 'boolean',
            'threshold_value' => 'float',
            'last_triggered_at' => 'datetime',
        ];
    }

    public function report()
    {
        return $this->belongsTo(ReportDefinition::class, 'report_definition_id');
    }
}
