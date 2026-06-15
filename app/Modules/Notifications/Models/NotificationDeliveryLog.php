<?php

namespace App\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDeliveryLog extends Model
{
    protected $fillable = ['company_id', 'user_id', 'event_type', 'channel', 'status', 'provider', 'destination', 'error', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
