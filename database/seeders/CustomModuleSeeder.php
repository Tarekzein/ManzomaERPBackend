<?php

namespace Database\Seeders;

use App\Modules\CustomModules\Models\CustomModule;
use Illuminate\Database\Seeder;

class CustomModuleSeeder extends Seeder
{
    public function run(): void
    {
        CustomModule::updateOrCreate(['slug' => 'example-approval-workflows'], [
            'name' => 'Approval Workflows',
            'version' => '1.0.0',
            'description' => 'Example approved extension manifest for configurable approval workflows.',
            'publisher' => 'ManzomaTech',
            'minimum_erp_version' => '0.1.0',
            'manifest' => [
                'permissions' => ['custom_modules.view', 'custom_modules.edit'],
                'navigation' => [],
                'settings_schema' => ['approval_levels' => ['type' => 'integer', 'minimum' => 1]],
            ],
            'status' => 'approved',
            'is_active' => true,
        ]);
    }
}
