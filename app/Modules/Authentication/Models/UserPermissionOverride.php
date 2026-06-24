<?php

namespace App\Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPermissionOverride extends Model
{
    public const EFFECT_ALLOW = 'allow';

    public const EFFECT_DENY = 'deny';

    protected $fillable = ['user_id', 'permission_name', 'effect'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
