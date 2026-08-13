<?php

namespace App\Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A side effect queued inside the checkout transaction.
 *
 * Receipt emails, webhooks and integrations are written here rather than
 * dispatched inline, so a rolled-back sale cannot leave a customer holding a
 * confirmation for a purchase that never happened.
 */
class PosOutboxEvent extends Model
{
    protected $fillable = [
        'company_id',
        'event',
        'subject_type',
        'subject_id',
        'payload',
        'available_at',
        'processed_at',
        'failed_at',
        'attempts',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
