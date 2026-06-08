<?php

namespace App\Modules\Projects\Repositories;

use App\Modules\Projects\Contracts\ProjectActivityRepository;
use App\Modules\Projects\Models\ProjectComment;
use App\Modules\Projects\Models\ProjectExpense;
use App\Modules\Projects\Models\ProjectFileAttachment;
use App\Modules\Projects\Models\ProjectTimeLog;

class EloquentProjectActivityRepository implements ProjectActivityRepository
{
    public function logTime(array $attributes): ProjectTimeLog
    {
        return ProjectTimeLog::query()->create($attributes);
    }

    public function attachFile(array $attributes): ProjectFileAttachment
    {
        return ProjectFileAttachment::query()->create($attributes);
    }

    public function comment(array $attributes): ProjectComment
    {
        return ProjectComment::query()->create($attributes);
    }

    public function expense(array $attributes): ProjectExpense
    {
        return ProjectExpense::query()->create($attributes);
    }
}
