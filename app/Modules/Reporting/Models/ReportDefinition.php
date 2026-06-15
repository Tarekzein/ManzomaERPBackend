<?php

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

class ReportDefinition extends Model
{
    protected $fillable = ['company_id', 'created_by', 'name', 'description', 'source', 'fields', 'filters', 'groupings', 'metrics', 'chart_type', 'is_shared'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'filters' => 'array', 'groupings' => 'array', 'metrics' => 'array', 'is_shared' => 'boolean'];
    }

    public function schedules()
    {
        return $this->hasMany(ReportSchedule::class);
    }
}
