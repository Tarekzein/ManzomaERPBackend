<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_features', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('module')->index();
            $table->text('description')->nullable();
            $table->boolean('is_metered')->default(false);
            $table->timestamps();
        });

        Schema::create('plan_feature', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_feature_id')->constrained()->cascadeOnDelete();
            $table->string('value')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['subscription_plan_id', 'subscription_feature_id'], 'plan_feature_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_feature');
        Schema::dropIfExists('subscription_features');
    }
};
