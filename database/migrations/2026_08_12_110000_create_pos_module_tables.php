<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Point of sale.
 *
 * Two rules run through every table here:
 *
 * 1. `company_id` is on every row, including child rows. A queued job or a
 *    report runs without an HTTP request and therefore without CompanyContext;
 *    carrying the tenant on the row itself is what keeps those paths safe.
 *
 * 2. Money and quantities are `decimal(18,4)`, matching Finance and Inventory,
 *    so a POS total, the invoice it posts and the stock it issues are the same
 *    numbers rather than three roundings of the same number.
 *
 * A finalized sale is immutable. Corrections are returns, refunds or voids —
 * never edits or deletes — which is why lines snapshot the product name, price
 * and cost as they were at the moment of sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->char('currency', 3)->default('EGP');
            $table->string('receipt_prefix', 12)->default('POS');
            $table->boolean('is_active')->default(true);
            // Default walk-in customer, tax behaviour, stock policy, variance
            // threshold and receipt template all live here so a register can
            // be retuned without a migration.
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('pos_register_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_register_id')->constrained('pos_registers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 24)->default('cashier');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();

            $table->unique(['pos_register_id', 'user_id', 'role'], 'pos_assignment_unique');
            $table->index(['company_id', 'user_id']);
        });

        Schema::create('pos_register_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_register_id')->constrained('pos_registers')->cascadeOnDelete();
            $table->string('tender_type', 24);
            $table->string('label');
            $table->string('provider', 40)->nullable();
            // Where the money lands, and where card takings wait until the
            // acquirer settles them.
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('clearing_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('opens_drawer')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['pos_register_id', 'tender_type'], 'pos_register_tender_unique');
        });

        Schema::create('pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_register_id')->constrained('pos_registers')->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('open');
            $table->date('business_date');
            $table->decimal('opening_float', 18, 4)->default(0);
            $table->decimal('expected_cash', 18, 4)->default(0);
            $table->decimal('counted_cash', 18, 4)->nullable();
            $table->decimal('cash_variance', 18, 4)->nullable();
            $table->json('counted_denominations')->nullable();
            $table->decimal('sales_total', 18, 4)->default(0);
            $table->decimal('returns_total', 18, 4)->default(0);
            $table->unsignedInteger('sale_count')->default(0);
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('variance_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('variance_approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['pos_register_id', 'status']);
            $table->index(['company_id', 'business_date']);
        });

        Schema::create('pos_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_shift_id')->constrained('pos_shifts')->cascadeOnDelete();
            $table->string('type', 16);
            $table->decimal('amount', 18, 4)->default(0);
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'pos_shift_id']);
        });

        Schema::create('pos_sales', function (Blueprint $table) {
            $table->id();
            // A client-generated identity so a retry can be recognised even
            // before the sale has a receipt number.
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_register_id')->constrained('pos_registers')->restrictOnDelete();
            $table->foreignId('pos_shift_id')->constrained('pos_shifts')->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('sales_contact_id')->nullable()->constrained('sales_contacts')->nullOnDelete();
            $table->foreignId('crm_contact_id')->nullable()->constrained('crm_contacts')->nullOnDelete();
            $table->string('status', 16)->default('completed');
            $table->string('receipt_number')->nullable();
            $table->date('business_date');
            $table->char('currency', 3)->default('EGP');
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('discount_total', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('rounding_total', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->decimal('paid_total', 18, 4)->default(0);
            $table->decimal('change_total', 18, 4)->default(0);
            $table->decimal('cost_total', 18, 4)->default(0);
            $table->decimal('returned_total', 18, 4)->default(0);
            // What this sale became in the authoritative modules.
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->foreignId('cogs_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'receipt_number']);
            $table->index(['company_id', 'business_date']);
            $table->index(['company_id', 'status']);
            $table->index(['pos_shift_id']);
            $table->index(['company_id', 'cashier_id']);
        });

        Schema::create('pos_sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_sale_id')->constrained('pos_sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedSmallInteger('line_number')->default(1);
            // Snapshots: a receipt reprinted next year must show what was sold
            // then, not what the catalogue says today.
            $table->string('product_name');
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('line_subtotal', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('cost_total', 18, 4)->default(0);
            $table->decimal('returned_quantity', 18, 4)->default(0);
            $table->timestamps();

            $table->index(['company_id', 'product_id']);
            $table->index(['pos_sale_id']);
        });

        Schema::create('pos_tenders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_sale_id')->constrained('pos_sales')->cascadeOnDelete();
            $table->string('tender_type', 24);
            $table->string('provider', 40)->nullable();
            $table->decimal('amount', 18, 4)->default(0);
            $table->decimal('tendered_amount', 18, 4)->default(0);
            $table->decimal('change_amount', 18, 4)->default(0);
            $table->decimal('refunded_amount', 18, 4)->default(0);
            $table->string('status', 16)->default('captured');
            // Never a PAN: a masked tail and the provider's own reference only.
            $table->string('reference')->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->string('card_brand', 24)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'tender_type']);
            $table->index(['pos_sale_id']);
        });

        Schema::create('pos_payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_sale_id')->nullable()->constrained('pos_sales')->cascadeOnDelete();
            $table->foreignId('pos_register_id')->constrained('pos_registers')->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('external_reference')->nullable();
            $table->string('state', 24)->default('pending');
            $table->decimal('amount', 18, 4)->default(0);
            $table->unsignedSmallInteger('attempt')->default(1);
            // Only a verified provider response is stored, and it is redacted
            // before it reaches the audit trail.
            $table->json('provider_response')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'provider', 'external_reference'], 'pos_attempt_reference_unique');
            $table->index(['company_id', 'state']);
        });

        Schema::create('pos_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_register_id')->constrained('pos_registers')->cascadeOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sales_contact_id')->nullable()->constrained('sales_contacts')->nullOnDelete();
            $table->string('label')->nullable();
            // Presentation only. A held cart is repriced from scratch when it
            // is resumed; these numbers are never trusted for accounting.
            $table->json('cart');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'pos_register_id']);
        });

        Schema::create('pos_returns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_sale_id')->nullable()->constrained('pos_sales')->nullOnDelete();
            $table->foreignId('pos_register_id')->constrained('pos_registers')->restrictOnDelete();
            $table->foreignId('pos_shift_id')->nullable()->constrained('pos_shifts')->nullOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('completed');
            $table->string('receipt_number')->nullable();
            $table->date('business_date');
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->decimal('cost_total', 18, 4)->default(0);
            $table->string('reason')->nullable();
            $table->foreignId('credit_note_id')->nullable()->constrained('credit_notes')->nullOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->foreignId('cogs_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'receipt_number']);
            $table->index(['company_id', 'business_date']);
        });

        Schema::create('pos_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_return_id')->constrained('pos_returns')->cascadeOnDelete();
            $table->foreignId('pos_sale_line_id')->nullable()->constrained('pos_sale_lines')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('cost_total', 18, 4)->default(0);
            // restock returns to sellable stock; damaged and non_restock do not.
            $table->string('disposition', 16)->default('restock');
            $table->timestamps();

            $table->index(['company_id', 'product_id']);
        });

        Schema::create('pos_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_return_id')->constrained('pos_returns')->cascadeOnDelete();
            $table->foreignId('pos_tender_id')->nullable()->constrained('pos_tenders')->nullOnDelete();
            $table->string('tender_type', 24);
            $table->decimal('amount', 18, 4)->default(0);
            $table->string('status', 16)->default('completed');
            $table->string('provider_reference')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('pos_outbox_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('event', 64);
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->json('payload')->nullable();
            // Written inside the checkout transaction and dispatched after it
            // commits, so a receipt email can never describe a sale that was
            // rolled back.
            $table->timestamp('available_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'processed_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'pos_outbox_events',
            'pos_refunds',
            'pos_return_lines',
            'pos_returns',
            'pos_holds',
            'pos_payment_attempts',
            'pos_tenders',
            'pos_sale_lines',
            'pos_sales',
            'pos_cash_movements',
            'pos_shifts',
            'pos_register_payment_methods',
            'pos_register_assignments',
            'pos_registers',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
