<?php

namespace App\Modules\Authentication\Contracts;

use App\Modules\Authentication\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface UserRepository
{
    public function findByEmail(string $email): ?User;

    public function create(array $attributes): User;

    public function save(User $user, array $attributes): User;

    public function paginate(?int $companyId, int $perPage): LengthAwarePaginator;

    public function loadProfile(User $user): User;
}
