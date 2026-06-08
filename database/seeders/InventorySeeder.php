<?php

namespace Database\Seeders;

use App\Modules\Companies\Models\Company;
use App\Modules\Inventory\Services\InventorySetupService;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    public function run(): void
    {
        Company::each(fn (Company $company) => app(InventorySetupService::class)->provision($company));
    }
}
