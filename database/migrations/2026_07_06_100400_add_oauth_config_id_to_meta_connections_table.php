<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_connections', function (Blueprint $table) {
            $table->string('oauth_config_id')->nullable()->after('app_secret');
        });
    }

    public function down(): void
    {
        Schema::table('meta_connections', function (Blueprint $table) {
            $table->dropColumn('oauth_config_id');
        });
    }
};
