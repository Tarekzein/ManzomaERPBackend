<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

class OrganizationStructureSeeder extends Seeder
{
    public function run(): void
    {
        $exitCode = Artisan::call('organizations:backfill', [
            '--fail-on-issues' => true,
        ]);

        if ($exitCode !== 0) {
            throw new RuntimeException('Organization structure reconciliation failed after seeding.');
        }
    }
}
