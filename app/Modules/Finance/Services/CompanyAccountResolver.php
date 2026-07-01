<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Account;
use Illuminate\Validation\ValidationException;

class CompanyAccountResolver
{
    public function byCode(int $companyId, string $code, ?string $type = null, ?string $label = null): Account
    {
        $query = Account::where('company_id', $companyId)->where('code', $code)->where('is_active', true);
        if ($type) {
            $query->where('type', $type);
        }

        $account = $query->first();
        if (! $account) {
            throw ValidationException::withMessages([
                'account_id' => [($label ?? "Account {$code}").' is not configured for this company.'],
            ]);
        }

        return $account;
    }

    public function ensure(int $companyId, int $accountId, ?string $type = null, ?string $label = null): Account
    {
        $query = Account::where('company_id', $companyId)->whereKey($accountId)->where('is_active', true);
        if ($type) {
            $query->where('type', $type);
        }

        $account = $query->first();
        if (! $account) {
            throw ValidationException::withMessages([
                'account_id' => [($label ?? 'The selected account').' must belong to the company and match the required type.'],
            ]);
        }

        return $account;
    }
}
