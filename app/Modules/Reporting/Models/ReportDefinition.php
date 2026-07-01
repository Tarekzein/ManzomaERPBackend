<?php

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

class ReportDefinition extends Model
{
    protected $fillable = ['company_id', 'created_by', 'name', 'description', 'source', 'fields', 'filters', 'groupings', 'metrics', 'chart_type', 'is_shared', 'share_token'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'filters' => 'array', 'groupings' => 'array', 'metrics' => 'array', 'is_shared' => 'boolean'];
    }

    public function schedules()
    {
        return $this->hasMany(ReportSchedule::class);
    }

    public function favoritedBy()
    {
        return $this->belongsToMany(\App\Modules\Authentication\Models\User::class, 'report_favorites', 'report_definition_id', 'user_id')
            ->withTimestamps();
    }

    public function getShareUrlAttribute(): ?string
    {
        return $this->share_token
            ? config('app.url') . '/shared-report/' . $this->share_token
            : null;
    }
}
