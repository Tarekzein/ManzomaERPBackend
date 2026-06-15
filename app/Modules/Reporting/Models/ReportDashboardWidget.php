<?php

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

class ReportDashboardWidget extends Model
{
    protected $fillable = ['company_id', 'user_id', 'report_definition_id', 'title', 'source', 'chart_type', 'configuration', 'position', 'width'];

    protected function casts(): array
    {
        return ['configuration' => 'array'];
    }
}
