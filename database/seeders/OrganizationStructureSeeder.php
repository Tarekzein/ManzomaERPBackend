<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class OrganizationStructureSeeder extends Seeder
{
    public function run(): void
    {
        // Permit catalog-only seeding before the organization migration has
        // been deployed. Once the foundation exists, reconciliation remains a
        // mandatory, fail-closed deployment check.
        if (! Schema::hasTable('organizations') || ! Schema::hasTable('company_memberships')) {
            return;
        }

        $exitCode = Artisan::call('organizations:backfill', [
            '--fail-on-issues' => true,
        ]);

        if ($exitCode !== 0) {
            throw new RuntimeException('Organization structure reconciliation failed after seeding.');
        }
    }
}
