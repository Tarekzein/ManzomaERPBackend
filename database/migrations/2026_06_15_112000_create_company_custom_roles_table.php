<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_custom_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('permissions');
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('custom_role_id')->nullable()->after('company_id')->constrained('company_custom_roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('custom_role_id'));
        Schema::dropIfExists('company_custom_roles');
    }
};
