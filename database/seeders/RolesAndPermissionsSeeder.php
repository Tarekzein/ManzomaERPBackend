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
            'pos',
            'projects',
            'reporting',
            'notifications',
            'custom_modules',
            'subscriptions',
            'meta',
            'tiktok',
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
                'hr.payroll.view',
                'hr.payroll.edit',
                'hr.documents.view',
                'hr.documents.edit',
                'hr.leave.approve',
                'hr.recruitment.manage',
                'hr.performance.manage',
                'hr.disciplinary.manage',
                // POS separates *selling* from *overriding*. A cashier holds
                // pos.sell and nothing that changes a price, reverses a sale or
                // opens the drawer outside a transaction; those need a
                // supervisor, which is what makes the audit trail meaningful.
                'pos.sell',
                'pos.hold',
                'pos.discount',
                'pos.price_override',
                'pos.open_shift',
                'pos.close_shift',
                'pos.cash.manage',
                'pos.void',
                'pos.return',
                'pos.return_without_receipt',
                'pos.refund',
                'pos.supervisor_override',
                'pos.registers.manage',
                'pos.settings.manage',
                'pos.reports.view',
                'pos.reports.export',
            ])
            ->unique()
            ->values();

        $permissions->each(fn (string $permission) => Permission::findOrCreate($permission));

        $superAdmin = Role::findOrCreate(UserRole::SuperAdmin->value);
        $companyAdmin = Role::findOrCreate(UserRole::CompanyAdmin->value);
        $manager = Role::findOrCreate(UserRole::Manager->value);
        $employee = Role::findOrCreate(UserRole::Employee->value);
        // Seeders can be run during a live deployment. Grant the permissions
        // introduced by the application without replacing permissions that a
        // platform administrator may already have added to these roles. In
        // particular, never delete legacy/custom roles such as "Viewer".
        $superAdmin->givePermissionTo(Permission::all());

        $companyAdmin->givePermissionTo($permissions->reject(
            fn (string $permission) => str_starts_with($permission, 'platform.')
        ));

        $managerModules = ['hr', 'finance', 'inventory', 'sales', 'crm', 'projects', 'reporting', 'notifications', 'meta', 'tiktok'];
        $manager->givePermissionTo($permissions->filter(
            fn (string $permission) => in_array($permission, ['users.view', 'users.create', 'users.edit', 'roles.assign', 'auth.force_password_reset'], true)
                || (in_array(explode('.', $permission)[0] ?? '', $managerModules, true)
                && (str_ends_with($permission, '.view')
                || str_ends_with($permission, '.create')
                || str_ends_with($permission, '.edit')
                || str_ends_with($permission, '.export')))
        )->merge([
            'hr.documents.view',
            'hr.documents.edit',
            'hr.leave.approve',
            'hr.recruitment.manage',
            'hr.performance.manage',
        ]));

        $employee->givePermissionTo([
            'hr.view',
            'projects.view',
            'projects.create',
            'projects.edit',
            'notifications.view',
        ]);

        $this->seedPosRoleTemplates();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * POS role templates.
     *
     * Separate from the four company roles because a till is staffed by people
     * who need very little of the ERP: a cashier can sell and hold, and nothing
     * else. Overrides, returns and reversals escalate to a supervisor, and
     * register/tender configuration to an administrator.
     */
    private function seedPosRoleTemplates(): void
    {
        // EnforceCompanyAccess gates every /api/pos/* route on the coarse
        // module verb (GET → pos.view, POST → pos.create). Those are the door;
        // the fine-grained pos.* permissions below are what PosPolicy actually
        // decides on, so holding pos.create alone still sells nothing.
        // pos.edit is needed because EnforceCompanyAccess maps any path
        // containing "close" or "move" to the edit verb — closing your own
        // shift is one. The fine-grained gates below are what actually decide:
        // a cashier holding pos.edit still cannot take cash out of the drawer
        // without pos.cash.manage.
        $cashier = [
            'pos.view',
            'pos.create',
            'pos.edit',
            // Releasing your own held cart is a DELETE, which the middleware
            // maps to pos.delete. The only POS delete routes are holds and
            // register assignments, and the latter needs pos.registers.manage.
            'pos.delete',
            'pos.sell',
            'pos.hold',
            'pos.open_shift',
            'pos.close_shift',
            'inventory.view',
        ];

        $supervisor = array_merge($cashier, [
            'pos.discount',
            'pos.price_override',
            'pos.cash.manage',
            'pos.void',
            'pos.return',
            'pos.return_without_receipt',
            'pos.refund',
            'pos.supervisor_override',
            'pos.reports.view',
        ]);

        $administrator = array_merge($supervisor, [
            'pos.export',
            'pos.registers.manage',
            'pos.settings.manage',
            'pos.reports.export',
        ]);

        foreach ([
            'POS Cashier' => $cashier,
            'POS Supervisor' => $supervisor,
            'POS Administrator' => $administrator,
        ] as $name => $permissions) {
            Role::findOrCreate($name)->givePermissionTo($permissions);
        }
    }
}
