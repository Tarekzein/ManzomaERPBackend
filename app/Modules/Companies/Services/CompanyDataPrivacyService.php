<?php

namespace App\Modules\Companies\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ZipArchive;

class CompanyDataPrivacyService
{
    public function export(User $actor, Company $company): string
    {
        $this->authorize($actor, $company);
        $path = storage_path('app/private/company-exports/'.Str::uuid().'.zip');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }

        $zip = new ZipArchive;
        abort_unless($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500, 'Unable to create export archive.');
        $zip->addFromString('company.json', json_encode($company->load('users.roles', 'subscriptions.plan'), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        foreach ($this->companyTables() as $table) {
            $rows = DB::table($table)->where('company_id', $company->id)->get();
            $zip->addFromString("data/{$table}.json", json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }

        $zip->close();

        return $path;
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

    private function companyTables(): array
    {
        return collect(Schema::getTables())
            ->pluck('name')
            ->filter(fn (string $table) => Schema::hasColumn($table, 'company_id') && $table !== 'companies')
            ->values()
            ->all();
    }
}
