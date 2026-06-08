<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Companies\Models\Company;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;

class InventorySetupService
{
    public function provision(Company $company): void
    {
        foreach ([['name' => 'Piece', 'symbol' => 'pcs', 'precision' => 0], ['name' => 'Kilogram', 'symbol' => 'kg', 'precision' => 3], ['name' => 'Liter', 'symbol' => 'L', 'precision' => 3]] as $unit) {
            Unit::updateOrCreate(['company_id' => $company->id, 'symbol' => $unit['symbol']], $unit);
        }
        $warehouse = Warehouse::updateOrCreate(['company_id' => $company->id, 'code' => 'MAIN'], ['name' => 'Main Warehouse', 'is_active' => true]);
        WarehouseLocation::updateOrCreate(['company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'code' => 'DEFAULT'], ['name' => 'Default Location']);
    }
}
