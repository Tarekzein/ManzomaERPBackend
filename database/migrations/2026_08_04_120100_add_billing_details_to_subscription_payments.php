<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->foreignId('company_subscription_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
            $table->string('purpose')->default('registration')->after('billing_cycle');
            // One payment per subscription period keeps the renewal command
            // idempotent when it runs more than once inside the same window.
            $table->string('billing_period_key')->nullable()->after('purpose');
            $table->timestamp('period_starts_at')->nullable()->after('billing_period_key');
            $table->timestamp('period_ends_at')->nullable()->after('period_starts_at');
            $table->unsignedTinyInteger('attempts')->default(0)->after('status');
            $table->timestamp('next_retry_at')->nullable()->after('attempts');
            $table->string('failure_reason')->nullable()->after('next_retry_at');
            $table->timestamp('refunded_at')->nullable()->after('failed_at');
            $table->timestamp('checkout_expires_at')->nullable()->after('checkout_url');

            $table->unique(['company_subscription_id', 'billing_period_key'], 'subscription_payments_period_unique');
            $table->index(['status', 'next_retry_at'], 'subscription_payments_retry_idx');
            $table->index(['company_id', 'purpose', 'status'], 'subscription_payments_purpose_idx');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropForeign(['company_subscription_id']);
            $table->dropUnique('subscription_payments_period_unique');
            $table->dropIndex('subscription_payments_retry_idx');
            $table->dropIndex('subscription_payments_purpose_idx');
            $table->dropColumn([
                'company_subscription_id',
                'purpose',
                'billing_period_key',
                'period_starts_at',
                'period_ends_at',
                'attempts',
                'next_retry_at',
                'failure_reason',
                'refunded_at',
                'checkout_expires_at',
            ]);
        });
    }
};
