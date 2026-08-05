<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            // Paymob refuses to reuse a special_reference ("An Order with ref X
            // already exists"), so every checkout attempt gets its own and we
            // resolve callbacks through it.
            $table->string('provider_reference')->nullable()->unique()->after('provider_order_id');
            $table->unsignedTinyInteger('checkout_attempts')->default(0)->after('attempts');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropUnique(['provider_reference']);
            $table->dropColumn(['provider_reference', 'checkout_attempts']);
        });
    }
};
