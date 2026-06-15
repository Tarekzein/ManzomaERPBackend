<?php

namespace App\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'event_type', 'in_app', 'email', 'sms'];

    protected function casts(): array
    {
        return ['in_app' => 'boolean', 'email' => 'boolean', 'sms' => 'boolean'];
    }
}
