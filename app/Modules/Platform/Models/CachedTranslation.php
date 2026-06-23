<?php

namespace App\Modules\Platform\Models;

use App\Modules\Companies\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CachedTranslation extends Model
{
    protected $table = 'translation_cache';

    protected $fillable = [
        'company_id', 'source_locale', 'target_locale', 'source_hash', 'translated_text', 'provider',
    ];

    protected function casts(): array
    {
        return ['translated_text' => 'encrypted'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
