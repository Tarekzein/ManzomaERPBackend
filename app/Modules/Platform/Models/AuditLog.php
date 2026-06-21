<?php

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'company_id', 'user_id', 'event', 'auditable_type', 'auditable_id',
        'old_values', 'new_values', 'ip_address', 'user_agent', 'request_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
