<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency receipts for commands that must not run twice.
 *
 * A cashier whose checkout request times out will press the button again. The
 * money has possibly already moved, so the second attempt must return the
 * first result rather than sell the stock twice. The client sends a stable key
 * per logical command; this table claims it, and the stored response is
 * replayed for every retry that follows.
 *
 * `request_hash` guards against a key being reused for a *different* payload,
 * which is a client bug rather than a retry and must not silently return
 * somebody else's receipt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('command_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('command', 64);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->string('status', 16)->default('in_progress');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('response')->nullable();
            $table->string('resource_type')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // The claim: one row per company + command + key. A concurrent
            // duplicate loses the insert race and reads the winner's result.
            $table->unique(['company_id', 'command', 'idempotency_key'], 'command_receipt_key_unique');
            $table->index(['company_id', 'status']);
            $table->index(['resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_receipts');
    }
};
