<?php

namespace App\Modules\Projects\Contracts;

use App\Modules\Projects\Models\ProjectComment;
use App\Modules\Projects\Models\ProjectExpense;
use App\Modules\Projects\Models\ProjectFileAttachment;
use App\Modules\Projects\Models\ProjectTimeLog;

interface ProjectActivityRepository
{
    public function logTime(array $attributes): ProjectTimeLog;

    public function attachFile(array $attributes): ProjectFileAttachment;

    public function comment(array $attributes): ProjectComment;

    public function expense(array $attributes): ProjectExpense;
}
