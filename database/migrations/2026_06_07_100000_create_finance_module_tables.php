<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_locked')->default(false);
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'starts_on', 'ends_on']);
        });
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('type');
            $table->string('subtype')->nullable();
            $table->string('currency', 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('allow_manual_entries')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'type']);
        });
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_period_id')->constrained()->restrictOnDelete();
            $table->string('number');
            $table->date('entry_date');
            $table->string('description');
            $table->string('status')->default('draft');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'entry_date', 'status']);
        });
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('description')->nullable();
            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);
            $table->string('currency', 3);
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('base_debit', 18, 4)->default(0);
            $table->decimal('base_credit', 18, 4)->default(0);
            $table->timestamps();
        });
        Schema::create('finance_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('currency', 3)->nullable();
            $table->json('address')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'type']);
        });
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('region')->nullable();
            $table->string('type');
            $table->decimal('rate', 8, 4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('finance_contacts')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('number');
            $table->date('invoice_date');
            $table->date('due_date');
            $table->string('currency', 3);
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->decimal('paid_total', 18, 4)->default(0);
            $table->string('status')->default('draft');
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'type', 'number']);
            $table->index(['company_id', 'type', 'status', 'due_date']);
        });
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('tax_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('subtotal', 18, 4);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('total', 18, 4);
            $table->timestamps();
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 18, 4);
            $table->string('currency', 3);
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->string('reference')->nullable();
            $table->timestamps();
        });
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('bank_name');
            $table->string('account_number')->nullable();
            $table->string('currency', 3);
            $table->decimal('opening_balance', 18, 4)->default(0);
            $table->timestamps();
        });
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->date('transaction_date');
            $table->string('description');
            $table->string('reference')->nullable();
            $table->decimal('amount', 18, 4);
            $table->boolean('is_reconciled')->default(false);
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
        });
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('draft');
            $table->timestamps();
        });
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->timestamps();
            $table->unique(['budget_id', 'account_id']);
        });
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('base_currency', 3);
            $table->string('quote_currency', 3);
            $table->decimal('rate', 18, 8);
            $table->date('rate_date');
            $table->string('source')->default('manual');
            $table->timestamps();
            $table->unique(['company_id', 'base_currency', 'quote_currency', 'rate_date'], 'exchange_rate_unique');
        });
    }

    public function down(): void
    {
        foreach (['exchange_rates', 'budget_lines', 'budgets', 'bank_transactions', 'bank_accounts', 'payments', 'invoice_lines', 'invoices', 'tax_rates', 'finance_contacts', 'journal_lines', 'journal_entries', 'accounts', 'financial_periods'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
