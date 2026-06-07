<?php

namespace App\Modules\Authentication\Contracts;

interface LoginAttemptRepository
{
    public function record(array $attributes): void;
}
