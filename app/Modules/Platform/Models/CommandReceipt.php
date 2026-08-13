<?php

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

class CommandReceipt extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id',
        'command',
        'idempotency_key',
        'request_hash',
        'claim_token',
        'status',
        'user_id',
        'response',
        'resource_type',
        'resource_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'response' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
