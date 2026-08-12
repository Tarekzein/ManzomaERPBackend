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

    public function requiresCompany(): bool
    {
        return $this !== self::SuperAdmin;
    }
}
