<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Document numbers were derived from a row count (which repeats after a
        // delete and races under concurrency) or from a timestamp plus a random
        // suffix (which collides against the unique index). One counter row per
        // company, prefix and period, incremented under a row lock, gives
        // numbers that are unique, sequential and auditable.
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('prefix', 16);
            $table->string('period', 8);       // usually the year
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'prefix', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
