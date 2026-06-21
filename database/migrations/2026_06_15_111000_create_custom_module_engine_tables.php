<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_modules', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('version')->default('1.0.0');
            $table->text('description')->nullable();
            $table->string('publisher')->nullable();
            $table->string('minimum_erp_version')->nullable();
            $table->json('manifest');
            $table->string('status')->default('approved');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('company_custom_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('custom_module_id')->constrained()->cascadeOnDelete();
            $table->string('installed_version');
            $table->string('status')->default('enabled');
            $table->json('settings')->nullable();
            $table->foreignId('installed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'custom_module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_custom_modules');
        Schema::dropIfExists('custom_modules');
    }
};
