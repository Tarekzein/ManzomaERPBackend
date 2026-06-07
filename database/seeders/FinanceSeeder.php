<?php

namespace Database\Seeders;

use App\Modules\Companies\Models\Company;
use App\Modules\Finance\Services\FinanceSetupService;
use Illuminate\Database\Seeder;

class FinanceSeeder extends Seeder
{
    public function run(): void
    {
        Company::each(fn (Company $company) => app(FinanceSetupService::class)->provision($company));
    }
}
