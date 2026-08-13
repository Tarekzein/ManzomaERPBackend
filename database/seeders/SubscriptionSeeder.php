<?php

namespace Database\Seeders;

use App\Modules\Subscriptions\Models\SubscriptionFeature;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use App\Modules\Subscriptions\Models\SubscriptionPlanPromotion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SubscriptionSeeder extends Seeder
{
    public function run(): void
    {
        $createdPlans = [];
        $features = [
            ['slug' => 'core.hr', 'name' => 'Human Resources', 'module' => 'hr', 'description' => 'Employee profiles, leave, attendance, payroll, and HR reports.'],
            ['slug' => 'core.finance', 'name' => 'Financial Accounting', 'module' => 'finance', 'description' => 'Chart of accounts, general ledger, AP, AR, statements, budgets, tax, and periods.'],
            ['slug' => 'core.inventory', 'name' => 'Inventory Management', 'module' => 'inventory', 'description' => 'Products, warehouses, stock movements, reorder alerts, valuation, and reports.'],
            ['slug' => 'core.sales', 'name' => 'Sales & Purchase Orders', 'module' => 'sales', 'description' => 'Quotes, sales orders, purchase orders, invoices, and delivery notes.'],
            ['slug' => 'core.crm', 'name' => 'CRM', 'module' => 'crm', 'description' => 'Contacts, opportunities, activities, reminders, segmentation, and CRM reports.'],
            ['slug' => 'core.pos', 'name' => 'Point of Sale', 'module' => 'pos', 'description' => 'Registers, shifts, retail checkout, receipts, returns, and cashier reporting.'],
            ['slug' => 'core.projects', 'name' => 'Project & Task Management', 'module' => 'projects', 'description' => 'Projects, tasks, Gantt-ready timelines, time tracking, files, and budget links.'],
            ['slug' => 'reporting.prebuilt', 'name' => 'Prebuilt Reports', 'module' => 'reporting', 'description' => 'Authorized reports across ERP modules.'],
            ['slug' => 'reporting.custom', 'name' => 'Custom Report Builder', 'module' => 'reporting', 'description' => 'Select fields, filters, groupings, chart types, and scheduled reports.'],
            ['slug' => 'exports.pdf_excel_csv', 'name' => 'PDF, Excel, CSV Exports', 'module' => 'reporting', 'description' => 'Export reports, invoices, payslips, and operational data.'],
            ['slug' => 'notifications.email', 'name' => 'Email Notifications', 'module' => 'notifications', 'description' => 'Configurable transactional email notifications.'],
            ['slug' => 'notifications.sms', 'name' => 'SMS Notifications', 'module' => 'notifications', 'description' => 'Critical alerts through SMS providers.'],
            ['slug' => 'integrations.api', 'name' => 'REST API Access', 'module' => 'platform', 'description' => 'External integration access through protected REST API.'],
            ['slug' => 'integrations.meta', 'name' => 'Meta (Facebook) Integration', 'module' => 'meta', 'description' => 'Conversions API, Lead Ads sync, and Custom Audiences sync with Meta Business.'],
            ['slug' => 'integrations.tiktok', 'name' => 'TikTok Integration', 'module' => 'tiktok', 'description' => 'TikTok Marketing API: advertiser sync, campaign reporting, and server-side conversion events.'],
            ['slug' => 'custom_modules.marketplace', 'name' => 'Custom Module Marketplace', 'module' => 'custom_modules', 'description' => 'Enable approved custom modules and feature flags per company.'],
            ['slug' => 'support.priority', 'name' => 'Priority Support', 'module' => 'platform', 'description' => 'Higher support priority for larger plans.'],
        ];

        $featureModels = collect($features)->mapWithKeys(function (array $feature) {
            $model = SubscriptionFeature::firstOrCreate(
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
                // POS needs Inventory and Finance behind it, both of which
                // Professional already includes.
                'core.pos',
                'reporting.prebuilt', 'reporting.custom', 'exports.pdf_excel_csv', 'notifications.email',
                'notifications.sms', 'integrations.api', 'integrations.meta', 'integrations.tiktok',
            ],
            'enterprise' => $featureModels->keys()->all(),
        ];

        collect($plans)->each(function (array $plan, string $slug) use ($featureModels, $matrix, &$createdPlans) {
            // Pricing, limits, visibility and descriptions are editable from
            // the platform dashboard. Existing values are commercial data,
            // not defaults, so a deployment seeder must never reset them.
            $planModel = SubscriptionPlan::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $plan['name'],
                    'description' => $plan['description'],
                    'monthly_price' => $plan['monthly_price'],
                    'annual_price' => $plan['annual_price'],
                    'currency' => $plan['currency'],
                    'max_users' => $plan['max_users'],
                    'max_companies' => array_key_exists('max_companies', $plan)
                        ? $plan['max_companies']
                        : 1,
                    'storage_gb' => $plan['storage_gb'],
                    'api_rate_limit_per_minute' => $plan['api_rate_limit_per_minute'],
                    'trial_enabled' => $plan['trial_enabled'] ?? false,
                    'trial_days' => $plan['trial_days'] ?? 0,
                    'is_active' => true,
                    'sort_order' => array_search($slug, array_keys(config('erp.plans')), true) + 1,
                ]
            );
            $createdPlans[$slug] = $planModel->wasRecentlyCreated;

            // Insert newly introduced feature assignments only. `sync()` and
            // even `syncWithoutDetaching()` can overwrite live enablement and
            // metered values; insertOrIgnore preserves both existing pivots
            // and custom features while remaining safe under concurrent runs.
            $now = now();
            $rows = $featureModels->map(fn (SubscriptionFeature $feature) => [
                'subscription_plan_id' => $planModel->getKey(),
                'subscription_feature_id' => $feature->getKey(),
                'enabled' => in_array($feature->slug, $matrix[$slug], true),
                'value' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all();

            DB::table('plan_feature')->insertOrIgnore($rows);
        });

        $professional = SubscriptionPlan::where('slug', 'professional')->first();
        // A time-limited marketing campaign belongs to initial/demo setup,
        // never to an upgrade of an already-running catalog.
        if ($professional && ($createdPlans['professional'] ?? false)) {
            // Keep an existing campaign's dates, status and discount intact.
            // Re-running the old updateOrCreate() extended and reactivated the
            // promotion on every deployment.
            SubscriptionPlanPromotion::firstOrCreate(
                ['subscription_plan_id' => $professional->id, 'name' => 'Launch annual discount'],
                [
                    'discount_type' => SubscriptionPlanPromotion::TYPE_PERCENT,
                    'discount_value' => 20,
                    'billing_cycle' => SubscriptionPlanPromotion::CYCLE_ANNUAL,
                    'starts_at' => now()->startOfDay(),
                    'ends_at' => now()->addDays(30)->endOfDay(),
                    'is_active' => true,
                ]
            );
        }
    }
}
