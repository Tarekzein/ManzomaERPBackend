<?php

namespace App\Modules\Authentication\Actions\Fortify;

use App\Modules\Authentication\DTOs\RegisterData;
use App\Modules\Authentication\Models\User;
use App\Modules\Authentication\Services\AuthenticationService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(private readonly AuthenticationService $authentication) {}

    public function create(array $input): User
    {
        Validator::make($input, [
            'company_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
        ])->validate();

        return $this->authentication->createCompanyAdmin(RegisterData::from($input + ['device_name' => 'fortify']));
    }
}
