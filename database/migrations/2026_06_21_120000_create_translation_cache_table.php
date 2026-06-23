<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('source_locale', 10);
            $table->string('target_locale', 10);
            $table->char('source_hash', 64);
            $table->text('translated_text');
            $table->string('provider', 50);
            $table->timestamps();

            $table->unique(['company_id', 'source_locale', 'target_locale', 'source_hash'], 'translation_cache_lookup_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_cache');
    }
};
