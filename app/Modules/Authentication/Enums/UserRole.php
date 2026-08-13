<?php

namespace App\Modules\Authentication\Enums;

enum UserRole: string
{
    case SuperAdmin = 'Super Admin';
    case CompanyAdmin = 'Company Admin';
    case Manager = 'Manager';
    case Employee = 'Employee';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Roles that live inside a company workspace, most privileged first.
     *
     * Company Admin is the workspace administrator: it never implies any
     * organization-level access, which belongs to OrganizationMembership.
     */
    public static function companyManagedValues(): array
    {
        return [self::CompanyAdmin->value, self::Manager->value, self::Employee->value];
    }

    /**
     * Role templates a module ships for a specific job, assignable as a
     * workspace role alongside the three general ones.
     *
     * A till is staffed by people who need almost none of the ERP, so POS
     * provides its own graded set rather than forcing a cashier to be an
     * Employee with a pile of permission overrides.
     */
    public const MODULE_ROLE_TEMPLATES = [
        'POS Cashier',
        'POS Supervisor',
        'POS Administrator',
    ];

    /** Every role that may be attached to a company membership. */
    public static function workspaceAssignableValues(): array
    {
        return array_merge(self::companyManagedValues(), self::MODULE_ROLE_TEMPLATES);
    }

    public function requiresCompany(): bool
    {
        return $this !== self::SuperAdmin;
    }
}
