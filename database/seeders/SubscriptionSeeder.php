<?php

namespace Database\Seeders;

use App\Models\SubscriptionFeature;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionSeeder extends Seeder
{
    public function run(): void
    {
        $features = [
            ['slug' => 'core.hr', 'name' => 'Human Resources', 'module' => 'hr', 'description' => 'Employee profiles, leave, attendance, payroll, and HR reports.'],
            ['slug' => 'core.finance', 'name' => 'Financial Accounting', 'module' => 'finance', 'description' => 'Chart of accounts, general ledger, AP, AR, statements, budgets, tax, and periods.'],
            ['slug' => 'core.inventory', 'name' => 'Inventory Management', 'module' => 'inventory', 'description' => 'Products, warehouses, stock movements, reorder alerts, valuation, and reports.'],
            ['slug' => 'core.sales', 'name' => 'Sales & Purchase Orders', 'module' => 'sales', 'description' => 'Quotes, sales orders, purchase orders, invoices, and delivery notes.'],
            ['slug' => 'core.crm', 'name' => 'CRM', 'module' => 'crm', 'description' => 'Contacts, opportunities, activities, reminders, segmentation, and CRM reports.'],
            ['slug' => 'core.projects', 'name' => 'Project & Task Management', 'module' => 'projects', 'description' => 'Projects, tasks, Gantt-ready timelines, time tracking, files, and budget links.'],
            ['slug' => 'reporting.prebuilt', 'name' => 'Prebuilt Reports', 'module' => 'reporting', 'description' => 'Authorized reports across ERP modules.'],
            ['slug' => 'reporting.custom', 'name' => 'Custom Report Builder', 'module' => 'reporting', 'description' => 'Select fields, filters, groupings, chart types, and scheduled reports.'],
            ['slug' => 'exports.pdf_excel_csv', 'name' => 'PDF, Excel, CSV Exports', 'module' => 'reporting', 'description' => 'Export reports, invoices, payslips, and operational data.'],
            ['slug' => 'notifications.email', 'name' => 'Email Notifications', 'module' => 'notifications', 'description' => 'Configurable transactional email notifications.'],
            ['slug' => 'notifications.sms', 'name' => 'SMS Notifications', 'module' => 'notifications', 'description' => 'Critical alerts through SMS providers.'],
            ['slug' => 'integrations.api', 'name' => 'REST API Access', 'module' => 'platform', 'description' => 'External integration access through protected REST API.'],
            ['slug' => 'custom_modules.marketplace', 'name' => 'Custom Module Marketplace', 'module' => 'custom_modules', 'description' => 'Enable approved custom modules and feature flags per company.'],
            ['slug' => 'support.priority', 'name' => 'Priority Support', 'module' => 'platform', 'description' => 'Higher support priority for larger plans.'],
        ];

        $featureModels = collect($features)->mapWithKeys(function (array $feature) {
            $model = SubscriptionFeature::updateOrCreate(
                ['slug' => $feature['slug']],
                $feature + ['is_metered' => false]
            );

            return [$model->slug => $model];
        });

        $plans = config('erp.plans');

        $matrix = [
            'basic' => [
                'core.hr', 'core.finance', 'core.inventory', 'core.sales', 'core.crm',
                'reporting.prebuilt', 'exports.pdf_excel_csv', 'notifications.email',
            ],
            'professional' => [
                'core.hr', 'core.finance', 'core.inventory', 'core.sales', 'core.crm', 'core.projects',
                'reporting.prebuilt', 'reporting.custom', 'exports.pdf_excel_csv', 'notifications.email',
                'notifications.sms', 'integrations.api',
            ],
            'enterprise' => $featureModels->keys()->all(),
        ];

        collect($plans)->each(function (array $plan, string $slug) use ($featureModels, $matrix) {
            $planModel = SubscriptionPlan::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $plan['name'],
                    'description' => $plan['description'],
                    'monthly_price' => $plan['monthly_price'],
                    'annual_price' => $plan['annual_price'],
                    'currency' => $plan['currency'],
                    'max_users' => $plan['max_users'],
                    'storage_gb' => $plan['storage_gb'],
                    'api_rate_limit_per_minute' => $plan['api_rate_limit_per_minute'],
                    'is_active' => true,
                    'sort_order' => array_search($slug, array_keys(config('erp.plans')), true) + 1,
                ]
            );

            $sync = $featureModels->mapWithKeys(function (SubscriptionFeature $feature) use ($matrix, $slug) {
                return [
                    $feature->id => [
                        'enabled' => in_array($feature->slug, $matrix[$slug], true),
                        'value' => null,
                    ],
                ];
            })->all();

            $planModel->features()->sync($sync);
        });
    }
}
