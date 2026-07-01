<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->boolean('trial_enabled')->default(false)->after('api_rate_limit_per_minute');
            $table->unsignedSmallInteger('trial_days')->default(0)->after('trial_enabled');
        });

        Schema::create('subscription_plan_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_plan_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('discount_type');
            $table->decimal('discount_value', 12, 2);
            $table->string('billing_cycle')->default('both');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['subscription_plan_id', 'billing_cycle', 'is_active'], 'spp_plan_cycle_active_idx');
            $table->index(['starts_at', 'ends_at'], 'spp_window_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_promotions');

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['trial_enabled', 'trial_days']);
        });
    }
};
