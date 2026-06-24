<?php

namespace Database\Seeders;

use App\Modules\Authentication\Enums\UserRole;
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
                'users.view',
                'users.create',
                'users.edit',
                'users.delete',
                'auth.force_password_reset',
                'roles.assign',
                'audit.view',
                'subscriptions.manage',
                'feature_flags.manage',
            ])
            ->unique()
            ->values();

        $permissions->each(fn (string $permission) => Permission::findOrCreate($permission));

        $superAdmin = Role::findOrCreate(UserRole::SuperAdmin->value);
        $companyAdmin = Role::findOrCreate(UserRole::CompanyAdmin->value);
        $manager = Role::findOrCreate(UserRole::Manager->value);
        $employee = Role::findOrCreate(UserRole::Employee->value);
        Role::where('name', 'Viewer')->delete();

        $superAdmin->syncPermissions(Permission::all());

        $companyAdmin->syncPermissions($permissions->reject(
            fn (string $permission) => str_starts_with($permission, 'platform.')
        ));

        $managerModules = ['hr', 'finance', 'inventory', 'sales', 'crm', 'projects', 'reporting', 'notifications'];
        $manager->syncPermissions($permissions->filter(
            fn (string $permission) => in_array($permission, ['users.view', 'users.create', 'users.edit', 'roles.assign', 'auth.force_password_reset'], true)
                || (in_array(explode('.', $permission)[0] ?? '', $managerModules, true)
                && (str_ends_with($permission, '.view')
                || str_ends_with($permission, '.create')
                || str_ends_with($permission, '.edit')
                || str_ends_with($permission, '.export')))
        ));

        $employee->syncPermissions([
            'hr.view',
            'projects.view',
            'projects.create',
            'projects.edit',
            'notifications.view',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
