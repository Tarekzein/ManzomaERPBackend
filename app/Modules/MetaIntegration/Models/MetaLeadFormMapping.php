<?php

namespace App\Modules\MetaIntegration\Models;

use App\Modules\Authentication\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaLeadFormMapping extends Model
{
    protected $table = 'meta_lead_form_mappings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'field_mapping' => 'array',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MetaConnection::class, 'meta_connection_id');
    }

    public function defaultOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_owner_id');
    }
}
