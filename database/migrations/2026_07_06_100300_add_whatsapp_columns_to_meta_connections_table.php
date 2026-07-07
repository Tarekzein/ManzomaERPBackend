<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_connections', function (Blueprint $table) {
            $table->boolean('whatsapp_enabled')->default(false)->after('default_page_id');
            $table->string('whatsapp_business_account_id')->nullable()->after('whatsapp_enabled');
            $table->string('whatsapp_phone_number_id')->nullable()->after('whatsapp_business_account_id');
            $table->string('whatsapp_phone_number')->nullable()->after('whatsapp_phone_number_id');
            $table->index('whatsapp_phone_number_id');
        });
    }

    public function down(): void
    {
        Schema::table('meta_connections', function (Blueprint $table) {
            $table->dropIndex(['whatsapp_phone_number_id']);
            $table->dropColumn([
                'whatsapp_enabled',
                'whatsapp_business_account_id',
                'whatsapp_phone_number_id',
                'whatsapp_phone_number',
            ]);
        });
    }
};
