<?php

namespace Database\Seeders;

use App\Modules\Companies\Models\Company;
use App\Modules\HR\Services\HRSetupService;
use Illuminate\Database\Seeder;

class HRSeeder extends Seeder
{
    public function run(): void
    {
        Company::each(fn (Company $company) => app(HRSetupService::class)->provision($company));
    }
}
