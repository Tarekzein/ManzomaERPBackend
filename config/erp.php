<?php

return [
    'version' => env('ERP_VERSION', '0.1.0'),

    'api' => [
        'prefix' => 'api',
        'rate_limit_per_minute' => env('ERP_API_RATE_LIMIT', 60),
    ],

    'company_scope' => [
        'model' => 'single_database',
        'strategy' => 'users belong to companies; module data will use company_id scoping',
    ],

    'modules' => [
        'platform' => ['name' => 'Platform', 'enabled_by_default' => true],
        'companies' => ['name' => 'Company & Subscription Management', 'enabled_by_default' => true],
        'hr' => ['name' => 'Human Resources & Payroll', 'enabled_by_default' => true],
        'finance' => ['name' => 'Financial Accounting & Budgeting', 'enabled_by_default' => true],
        'inventory' => ['name' => 'Inventory & Warehouse Management', 'enabled_by_default' => true],
        'sales' => ['name' => 'Sales & Purchase Orders', 'enabled_by_default' => true],
        'crm' => ['name' => 'Customer Relationship Management', 'enabled_by_default' => true],
        'projects' => ['name' => 'Project & Task Management', 'enabled_by_default' => true],
        'reporting' => ['name' => 'Reporting & Business Intelligence', 'enabled_by_default' => true],
        'notifications' => ['name' => 'Notifications', 'enabled_by_default' => true],
        'custom_modules' => ['name' => 'Custom Module Engine', 'enabled_by_default' => false],
    ],

    'plans' => [
        'basic' => [
            'name' => 'Basic',
            'description' => 'Core ERP plan for small teams starting operations management.',
            'monthly_price' => 49,
            'annual_price' => 490,
            'currency' => 'USD',
            'max_users' => 25,
            'storage_gb' => 10,
            'api_rate_limit_per_minute' => 60,
        ],
        'professional' => [
            'name' => 'Professional',
            'description' => 'Advanced plan with automation, reporting, integrations, and custom reports.',
            'monthly_price' => 149,
            'annual_price' => 1490,
            'currency' => 'USD',
            'max_users' => 100,
            'storage_gb' => 100,
            'api_rate_limit_per_minute' => 120,
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'description' => 'Full ERP suite with custom modules, higher limits, and priority support.',
            'monthly_price' => 499,
            'annual_price' => 4990,
            'currency' => 'USD',
            'max_users' => null,
            'storage_gb' => 1000,
            'api_rate_limit_per_minute' => 300,
        ],
    ],

    'locales' => [
        'en' => ['direction' => 'ltr'],
        'ar' => ['direction' => 'rtl'],
    ],
];
