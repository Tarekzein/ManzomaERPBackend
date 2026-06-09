<?php

namespace App\Modules\Projects\Models;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Projects\Enums\ProjectStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'owner_id',
        'name',
        'description',
        'start_date',
        'end_date',
        'budget',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'budget' => 'decimal:2',
            'status' => ProjectStatus::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class);
    }

    public function timeLogs(): HasMany
    {
        return $this->hasMany(ProjectTimeLog::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ProjectFileAttachment::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ProjectComment::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(ProjectExpense::class);
    }

    protected static function newFactory(): ProjectFactory
    {
        return ProjectFactory::new();
    }
}
