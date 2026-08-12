<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            // Existing plans remain single-company until an administrator
            // explicitly increases the entitlement. NULL means unlimited.
            $table->unsignedInteger('max_companies')->nullable()->default(1)->after('max_users');
        });

        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('company_id')
                ->constrained('organizations')
                ->restrictOnDelete();
            $table->json('entitlements_snapshot')->nullable()->after('subscription_plan_id');
            $table->timestamp('over_limit_since')->nullable()->after('entitlements_snapshot');
            $table->foreignId('pending_plan_id')
                ->nullable()
                ->after('over_limit_since')
                ->constrained('subscription_plans')
                ->restrictOnDelete();
            $table->timestamp('pending_change_at')->nullable()->after('pending_plan_id');

            $table->index(['organization_id', 'status'], 'company_subscriptions_org_status_idx');
        });

        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('company_id')
                ->constrained('organizations')
                ->restrictOnDelete();
            $table->foreignId('initiated_from_company_id')
                ->nullable()
                ->after('organization_id')
                ->constrained('companies')
                ->nullOnDelete();

            $table->index(['organization_id', 'status'], 'subscription_payments_org_status_idx');
        });

        // Fresh installs may already have organization links from seed data.
        // Existing installations are completed by organizations:backfill after
        // migrations; preserving nullable keys keeps this deployment additive.
        DB::table('company_subscriptions')
            ->join('companies', 'companies.id', '=', 'company_subscriptions.company_id')
            ->join('subscription_plans', 'subscription_plans.id', '=', 'company_subscriptions.subscription_plan_id')
            ->select([
                'company_subscriptions.id',
                'companies.organization_id',
                'subscription_plans.max_companies',
                'subscription_plans.max_users',
                'subscription_plans.storage_gb',
                'subscription_plans.api_rate_limit_per_minute',
            ])
            ->chunkById(200, function ($subscriptions) {
                foreach ($subscriptions as $subscription) {
                    DB::table('company_subscriptions')->where('id', $subscription->id)->update([
                        'organization_id' => $subscription->organization_id,
                        'entitlements_snapshot' => json_encode([
                            'max_companies' => $subscription->max_companies === null ? null : (int) $subscription->max_companies,
                            'max_users' => $subscription->max_users === null ? null : (int) $subscription->max_users,
                            'storage_gb' => $subscription->storage_gb === null ? null : (int) $subscription->storage_gb,
                            'api_rate_limit_per_minute' => $subscription->api_rate_limit_per_minute === null
                                ? null
                                : (int) $subscription->api_rate_limit_per_minute,
                        ], JSON_THROW_ON_ERROR),
                    ]);
                }
            }, 'company_subscriptions.id', 'id');

        DB::table('subscription_payments')
            ->join('companies', 'companies.id', '=', 'subscription_payments.company_id')
            ->select(['subscription_payments.id', 'subscription_payments.company_id', 'companies.organization_id'])
            ->chunkById(200, function ($payments) {
                foreach ($payments as $payment) {
                    DB::table('subscription_payments')->where('id', $payment->id)->update([
                        'organization_id' => $payment->organization_id,
                        'initiated_from_company_id' => $payment->company_id,
                    ]);
                }
            }, 'subscription_payments.id', 'id');
    }

    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropIndex('subscription_payments_org_status_idx');
            $table->dropConstrainedForeignId('initiated_from_company_id');
            $table->dropConstrainedForeignId('organization_id');
        });

        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->dropIndex('company_subscriptions_org_status_idx');
            $table->dropConstrainedForeignId('pending_plan_id');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['entitlements_snapshot', 'over_limit_since', 'pending_change_at']);
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('max_companies');
        });
    }
};
