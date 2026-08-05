<?php

use App\Modules\Subscriptions\Support\BillingPeriod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->boolean('auto_renew')->default(true)->after('billing_cycle');
            $table->boolean('cancel_at_period_end')->default(false)->after('auto_renew');
            $table->timestamp('current_period_started_at')->nullable()->after('starts_at');
            $table->timestamp('current_period_ends_at')->nullable()->after('current_period_started_at');
            $table->timestamp('grace_ends_at')->nullable()->after('trial_ends_at');
            $table->string('cancellation_reason')->nullable()->after('cancelled_at');
            $table->text('payment_method_token')->nullable()->after('provider_subscription_id');
            $table->string('payment_method_brand')->nullable()->after('payment_method_token');
            $table->string('payment_method_last4', 4)->nullable()->after('payment_method_brand');
            $table->unsignedTinyInteger('renewal_failures')->default(0)->after('payment_method_last4');
            $table->timestamp('last_renewal_attempt_at')->nullable()->after('renewal_failures');
            $table->timestamp('last_renewed_at')->nullable()->after('last_renewal_attempt_at');
            $table->json('reminders_sent')->nullable()->after('last_renewed_at');

            $table->index(['status', 'current_period_ends_at'], 'company_subscriptions_renewal_idx');
        });

        // Give existing subscriptions a billing period so the renewal command
        // has something to work from instead of treating them as due forever.
        DB::table('company_subscriptions')
            ->whereIn('status', ['active', 'trialing'])
            ->orderBy('id')
            ->chunkById(200, function ($subscriptions) {
                foreach ($subscriptions as $subscription) {
                    $start = Carbon::parse($subscription->starts_at ?? $subscription->created_at ?? now());
                    $end = $subscription->status === 'trialing' && $subscription->trial_ends_at
                        ? Carbon::parse($subscription->trial_ends_at)
                        : BillingPeriod::end((string) $subscription->billing_cycle, $start);

                    DB::table('company_subscriptions')->where('id', $subscription->id)->update([
                        'current_period_started_at' => $start,
                        'current_period_ends_at' => $end,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->dropIndex('company_subscriptions_renewal_idx');
            $table->dropColumn([
                'auto_renew',
                'cancel_at_period_end',
                'current_period_started_at',
                'current_period_ends_at',
                'grace_ends_at',
                'cancellation_reason',
                'payment_method_token',
                'payment_method_brand',
                'payment_method_last4',
                'renewal_failures',
                'last_renewal_attempt_at',
                'last_renewed_at',
                'reminders_sent',
            ]);
        });
    }
};
