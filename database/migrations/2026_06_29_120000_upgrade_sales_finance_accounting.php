<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'discount_total')) {
                $table->decimal('discount_total', 18, 4)->default(0)->after('subtotal');
            }
            if (! Schema::hasColumn('invoices', 'source_type')) {
                $table->string('source_type')->nullable()->after('notes');
                $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
                $table->index(['company_id', 'source_type', 'source_id'], 'invoices_source_index');
            }
            if (! Schema::hasColumn('invoices', 'credited_total')) {
                $table->decimal('credited_total', 18, 4)->default(0)->after('paid_total');
            }
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_lines', 'discount_percent')) {
                $table->decimal('discount_percent', 8, 4)->default(0)->after('unit_price');
            }
            if (! Schema::hasColumn('invoice_lines', 'discount_amount')) {
                $table->decimal('discount_amount', 18, 4)->default(0)->after('discount_percent');
            }
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->timestamps();
            $table->unique(['payment_id', 'invoice_id']);
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number');
            $table->date('credit_date');
            $table->decimal('amount', 18, 4);
            $table->string('reason')->nullable();
            $table->string('status')->default('posted');
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('payment_allocations');

        Schema::table('invoice_lines', function (Blueprint $table) {
            foreach (['discount_amount', 'discount_percent'] as $column) {
                if (Schema::hasColumn('invoice_lines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'source_type')) {
                $table->dropIndex('invoices_source_index');
                $table->dropColumn(['source_type', 'source_id']);
            }
            foreach (['credited_total', 'discount_total'] as $column) {
                if (Schema::hasColumn('invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
