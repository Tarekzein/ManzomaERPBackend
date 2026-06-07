<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $modules = [
            'platform',
            'companies',
            'hr',
            'finance',
            'inventory',
            'sales',
            'crm',
            'projects',
            'reporting',
            'notifications',
            'custom_modules',
            'subscriptions',
        ];

        $actions = ['view', 'create', 'edit', 'delete', 'export'];

        $permissions = collect($modules)
            ->flatMap(fn (string $module) => collect($actions)->map(fn (string $action) => "{$module}.{$action}"))
            ->merge([
                'auth.force_password_reset',
                'roles.assign',
                'audit.view',
                'subscriptions.manage',
                'feature_flags.manage',
            ])
            ->unique()
            ->values();

        $permissions->each(fn (string $permission) => Permission::findOrCreate($permission));

        $superAdmin = Role::findOrCreate('Super Admin');
        $companyAdmin = Role::findOrCreate('Company Admin');
        $manager = Role::findOrCreate('Manager');
        $employee = Role::findOrCreate('Employee');
        $viewer = Role::findOrCreate('Viewer');

        $superAdmin->syncPermissions(Permission::all());

        $companyAdmin->syncPermissions($permissions->reject(
            fn (string $permission) => str_starts_with($permission, 'platform.')
        ));

        $manager->syncPermissions($permissions->filter(
            fn (string $permission) => str_ends_with($permission, '.view')
                || str_ends_with($permission, '.create')
                || str_ends_with($permission, '.edit')
                || str_ends_with($permission, '.export')
        ));

        $employee->syncPermissions([
            'hr.view',
            'projects.view',
            'projects.create',
            'projects.edit',
            'notifications.view',
        ]);

        $viewer->syncPermissions($permissions->filter(
            fn (string $permission) => str_ends_with($permission, '.view')
        ));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
