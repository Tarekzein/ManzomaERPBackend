<?php

namespace App\Modules\Authentication\Repositories;

use App\Modules\Authentication\Contracts\LoginAttemptRepository;
use App\Modules\Authentication\Models\LoginAttempt;

class EloquentLoginAttemptRepository implements LoginAttemptRepository
{
    public function record(array $attributes): void
    {
        LoginAttempt::create($attributes);
    }
}
