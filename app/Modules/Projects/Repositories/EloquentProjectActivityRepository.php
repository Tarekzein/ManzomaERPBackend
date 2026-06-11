<?php

namespace App\Modules\Projects\Repositories;

use App\Modules\Projects\Contracts\ProjectActivityRepository;
use App\Modules\Projects\Models\ProjectComment;
use App\Modules\Projects\Models\ProjectExpense;
use App\Modules\Projects\Models\ProjectFileAttachment;
use App\Modules\Projects\Models\ProjectTimeLog;
use Illuminate\Database\Eloquent\Model;

class EloquentProjectActivityRepository implements ProjectActivityRepository
{
    public function logTime(array $attributes): ProjectTimeLog
    {
        return $this->create(ProjectTimeLog::class, $attributes);
    }

    public function attachFile(array $attributes): ProjectFileAttachment
    {
        return $this->create(ProjectFileAttachment::class, $attributes);
    }

    public function comment(array $attributes): ProjectComment
    {
        return $this->create(ProjectComment::class, $attributes);
    }

    public function expense(array $attributes): ProjectExpense
    {
        return $this->create(ProjectExpense::class, $attributes);
    }

    private function create(string $model, array $attributes): Model
    {
        return $model::query()->create($attributes);
    }
}
