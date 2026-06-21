<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->timestamp('last_activity_at')->nullable()->after('remember_token');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event');
            $table->string('auditable_type')->nullable();
            $table->string('auditable_id')->nullable();
            $table->index(['auditable_type', 'auditable_id']);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id')->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('usage_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('metric');
            $table->date('period_date');
            $table->unsignedBigInteger('quantity')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'metric', 'period_date']);
        });

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('url');
            $table->text('secret');
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('failure_count')->default(0);
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->uuid('delivery_id')->unique();
            $table->json('payload');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('status')->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('usage_metrics');
        Schema::dropIfExists('audit_logs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'last_activity_at']);
        });
    }
};
