<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One inbox for everything a customer says on social: page comments,
        // Instagram comments and direct messages, whichever platform they came
        // from.
        Schema::create('social_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('platform');            // facebook | instagram | whatsapp | tiktok
            $table->string('type');                // comment | message
            $table->string('external_id');         // the platform's id for this item
            $table->string('parent_external_id')->nullable(); // post/thread it belongs to
            $table->string('page_id')->nullable();
            $table->string('author_external_id')->nullable();
            $table->string('author_name')->nullable();
            $table->text('message')->nullable();
            $table->string('permalink')->nullable();
            $table->foreignId('crm_contact_id')->nullable()->constrained('crm_contacts')->nullOnDelete();
            $table->foreignId('crm_task_id')->nullable()->constrained('crm_tasks')->nullOnDelete();
            $table->string('status')->default('new'); // new | handled | ignored
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            // The platform id is the natural dedupe key for redelivered webhooks.
            $table->unique(['company_id', 'platform', 'external_id'], 'social_interactions_external_unique');
            $table->index(['company_id', 'status', 'posted_at']);
            $table->index(['company_id', 'platform', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_interactions');
    }
};
