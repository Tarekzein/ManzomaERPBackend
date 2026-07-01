<?php

namespace App\Modules\Companies\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Exports\CompanyDataExport;
use App\Modules\Companies\Models\Company;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CompanyDataPrivacyService
{
    public function export(User $actor, Company $company): BinaryFileResponse
    {
        $this->authorize($actor, $company);

        return Excel::download(new CompanyDataExport($company), "company-{$company->id}-export.xlsx");
    }

    public function erase(User $actor, Company $company, string $confirmation): void
    {
        abort_unless($actor->isSuperAdmin(), 403);
        abort_unless(hash_equals($company->name, $confirmation), 422, 'Company name confirmation does not match.');

        DB::transaction(function () use ($company) {
            $company->users()->each(fn (User $user) => $user->tokens()->delete());
            $company->delete();
        });
    }

    private function authorize(User $actor, Company $company): void
    {
        abort_unless($actor->isSuperAdmin() || ($actor->company_id === $company->id && $actor->can('companies.export')), 403);
    }
}
