<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suspension provenance for the tenant tree.
 *
 * Organizations already carry `status`, and companies already carry
 * `is_active`; neither records *when* or *why*. Those two facts are what the
 * suspended account is shown when it signs in, and what the platform reviews
 * before lifting a suspension, so they get real columns rather than settings
 * JSON. `suspended_by_user_id` stays unconstrained by design: deleting the
 * admin who suspended a tenant must never cascade into the tenant itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('billing_suspended_at');
            $table->string('suspension_reason', 500)->nullable()->after('suspended_at');
            $table->unsignedBigInteger('suspended_by_user_id')->nullable()->after('suspension_reason');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('is_active');
            $table->string('suspension_reason', 500)->nullable()->after('suspended_at');
            $table->unsignedBigInteger('suspended_by_user_id')->nullable()->after('suspension_reason');
        });

        Schema::table('company_memberships', function (Blueprint $table) {
            $table->string('suspension_reason', 500)->nullable()->after('suspended_at');
        });

        // Organization status is unambiguous, so existing suspensions can be
        // stamped and will explain themselves to their users.
        DB::table('organizations')
            ->where('status', 'suspended')
            ->whereNull('suspended_at')
            ->update(['suspended_at' => now()]);

        // Companies are deliberately left alone. `is_active = false` also
        // means "registered, awaiting first payment", and back-stamping those
        // would announce a suspension to accounts that were never suspended.
        // suspended_at is set from here on by the suspend endpoints only.
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'suspension_reason', 'suspended_by_user_id']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'suspension_reason', 'suspended_by_user_id']);
        });

        Schema::table('company_memberships', function (Blueprint $table) {
            $table->dropColumn('suspension_reason');
        });
    }
};
