<?php

namespace App\Modules\Companies\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Exports\CompanyDataExport;
use App\Modules\Companies\Models\Company;
use App\Modules\Platform\Services\CompanyContext;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CompanyDataPrivacyService
{
    public function __construct(private readonly CompanyContext $context) {}

    public function export(User $actor, Company $company): BinaryFileResponse
    {
        $this->authorize($actor, $company);

        return Excel::download(new CompanyDataExport($company), "company-{$company->id}-export.xlsx");
    }

    public function erase(User $actor, Company $company, string $confirmation): void
    {
        abort_unless($actor->isSuperAdmin(), 403);
        abort_unless(hash_equals($company->name, $confirmation), 422, 'Company name confirmation does not match.');
        abort_if(
            $company->organization_id
                && $company->organization()->first()?->companies()->whereKeyNot($company->getKey())->exists(),
            422,
            'A company inside a multi-company organization must be archived instead of erased.',
        );

        $organization = $company->organization()->first();
        $hasRetainedBillingHistory = $company->subscriptions()->exists()
            || $company->subscriptionPayments()->exists()
            || ($organization && (
                $organization->subscriptions()->exists()
                || $organization->subscriptionPayments()->exists()
            ));

        abort_if(
            $hasRetainedBillingHistory,
            422,
            'This company has retained subscription or payment history and cannot be erased. Suspend it instead.',
        );

        DB::transaction(function () use ($company, $organization) {
            $company->members()->get()
                ->merge($company->users()->get())
                ->unique('id')
                ->each(fn (User $user) => $user->tokens()->delete());
            $company->delete();
            $organization?->delete();
        });
    }

    private function authorize(User $actor, Company $company): void
    {
        abort_unless(
            $actor->isSuperAdmin()
            || ($this->context->companyIdFor($actor) === (int) $company->id && $actor->can('companies.export')),
            403,
        );
    }
}
