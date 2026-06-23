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

    public static function companyManagedValues(): array
    {
        return [self::Manager->value, self::Employee->value];
    }

    public function requiresCompany(): bool
    {
        return $this !== self::SuperAdmin;
    }
}
