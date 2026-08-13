<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\FinanceSetupService;
use App\Modules\Inventory\Models\InventoryCostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\InventorySetupService;
use App\Modules\Organizations\Models\CompanyMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Platform\Models\WebhookEndpoint;
use App\Modules\Platform\Services\WebhookService;
use App\Modules\POS\Jobs\ProcessPosOutboxEvent;
use App\Modules\POS\Models\PosHold;
use App\Modules\POS\Models\PosOutboxEvent;
use App\Modules\POS\Models\PosPaymentAttempt;
use App\Modules\POS\Models\PosRegister;
use App\Modules\POS\Models\PosRegisterAssignment;
use App\Modules\POS\Models\PosRegisterPaymentMethod;
use App\Modules\POS\Models\PosSale;
use App\Modules\POS\Models\PosShift;
use App\Modules\POS\Policies\PosPolicy;
use App\Modules\POS\Services\PosPricingService;
use App\Modules\POS\Services\PosTerminalService;
use App\Modules\Sales\Models\SalesContact;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionFeature;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use App\Modules\Subscriptions\Services\OrganizationEntitlementService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The §10 completion gates for cash checkout.
 */
class PosCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private PosRegister $register;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->buildTill();
    }

    public function test_a_cash_sale_writes_stock_invoice_payment_and_ledger_in_one_operation(): void
    {
        Sanctum::actingAs($this->cashier);
        $this->openShift();

        $response = $this->postJson('/api/pos/sales', [
            'idempotency_key' => 'sale-1',
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 3]],
            'tenders' => [['tender_type' => 'cash', 'amount' => '60.00', 'tendered_amount' => '100.00']],
        ])->assertCreated();

        $sale = PosSale::query()->findOrFail($response->json('data.id'));

        // Gate: sale total equals tenders and the Finance invoice total.
        $this->assertSame('60.0000', $sale->total);
        $this->assertSame('60.0000', $sale->paid_total);
        $this->assertSame('40.0000', $sale->change_total);

        $invoice = Invoice::query()->findOrFail($sale->invoice_id);
        $this->assertSame('60.0000', $invoice->total);
        $this->assertSame('paid', $invoice->status);

        // Gate: inventory equals the sold quantity.
        $balance = StockBalance::query()
            ->where('product_id', $this->product->id)
            ->where('warehouse_id', $this->register->warehouse_id)
            ->firstOrFail();
        $this->assertSame('7.0000', $balance->quantity);
        $this->assertNotNull($sale->stock_movement_id);

        // Gate: every posted journal balances.
        $this->assertJournalsBalance();

        // Gate: a receipt number was issued.
        $this->assertNotNull($sale->receipt_number);
    }

    public function test_retrying_the_same_checkout_creates_exactly_one_sale(): void
    {
        Sanctum::actingAs($this->cashier);
        $this->openShift();

        $payload = [
            'idempotency_key' => 'sale-retry',
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 2]],
            'tenders' => [['tender_type' => 'cash', 'amount' => '40.00']],
        ];

        $first = $this->postJson('/api/pos/sales', $payload)->assertCreated();
        $second = $this->postJson('/api/pos/sales', $payload)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, PosSale::query()->count());
        $this->assertSame(1, Invoice::query()->count());

        // The stock moved once, not twice.
        $this->assertSame('8.0000', StockBalance::query()->firstOrFail()->quantity);
    }

    public function test_a_completed_checkout_replays_after_the_shift_or_register_state_changes(): void
    {
        Sanctum::actingAs($this->cashier);
        $shift = $this->openShift();

        $payload = [
            'idempotency_key' => 'sale-replay-after-close',
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'tenders' => [['tender_type' => 'cash', 'amount' => '20.00']],
        ];

        $first = $this->postJson('/api/pos/sales', $payload)->assertCreated();

        // These mutable preconditions are relevant to a new sale, but an
        // exact retry must still receive the already-committed receipt.
        $shift->forceFill(['status' => PosShift::STATUS_CLOSED, 'closed_at' => now()])->save();
        $this->register->forceFill(['is_active' => false])->save();

        $replay = $this->postJson('/api/pos/sales', $payload)->assertOk();

        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, PosSale::query()->count());
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_a_failure_mid_checkout_leaves_no_sale_stock_invoice_or_payment(): void
    {
        Sanctum::actingAs($this->cashier);
        $this->openShift();

        // Tenders that do not cover the total abort after pricing and locking.
        $this->postJson('/api/pos/sales', [
            'idempotency_key' => 'sale-short',
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 2]],
            'tenders' => [['tender_type' => 'cash', 'amount' => '10.00']],
        ])->assertUnprocessable();

        $this->assertSame(0, PosSale::query()->count());
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame('10.0000', StockBalance::query()->firstOrFail()->quantity);
    }

    public function test_a_cart_cannot_oversell_the_available_stock(): void
    {
        Sanctum::actingAs($this->cashier);
        $this->openShift();

        $this->postJson('/api/pos/sales', [
            'idempotency_key' => 'sale-oversell',
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 11]],
            'tenders' => [['tender_type' => 'cash', 'amount' => '220.00']],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines');

        $this->assertSame(0, PosSale::query()->count());
    }

    public function test_a_legacy_negative_stock_setting_is_inert_and_cannot_oversell(): void
    {
        $this->register->forceFill([
            'settings' => ['stock' => ['allow_negative' => true]],
        ])->save();
        Sanctum::actingAs($this->cashier);
        $this->openShift();

        $this->postJson('/api/pos/sales', [
            'idempotency_key' => 'sale-negative-stock',
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 12]],
            'tenders' => [['tender_type' => 'cash', 'amount' => '240.00']],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines');

        $this->assertSame('10.0000', StockBalance::query()->firstOrFail()->quantity);
        $this->assertSame(0, PosSale::query()->count());
    }

    public function test_the_same_product_on_two_lines_is_checked_against_one_balance(): void
    {
        Sanctum::actingAs($this->cashier);
        $this->openShift();

        // 6 + 6 exceeds the 10 on hand even though neither line does alone.
        $this->postJson('/api/pos/sales', [
            'idempotency_key' => 'sale-split',
            'pos_register_id' => $this->register->id,
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 6],
                ['product_id' => $this->product->id, 'quantity' => 6],
            ],
            'tenders' => [['tender_type' => 'cash', 'amount' => '240.00']],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines');
    }

    public function test_the_client_cannot_dictate_the_price(): void
    {
        Sanctum::actingAs($this->cashier);
        $this->openShift();

        // A cashier without pos.price_override sends a forged unit price.
        $this->postJson('/api/pos/sales', [
            'idempotency_key' => 'sale-forged',
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => '0.01']],
            'tenders' => [['tender_type' => 'cash', 'amount' => '0.01']],
        ])->assertForbidden();

        $this->assertSame(0, PosSale::query()->count());
    }

    public function test_selling_requires_an_open_shift_and_a_register_assignment(): void
    {
        Sanctum::actingAs($this->cashier);

        // No shift yet.
        $this->postJson('/api/pos/sales', [
            'idempotency_key' => 'sale-no-shift',
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'tenders' => [['tender_type' => 'cash', 'amount' => '20.00']],
        ])->assertUnprocessable()->assertJsonValidationErrors('shift');

        // A colleague who is not on this register's roster.
        $stranger = $this->workspaceUser('POS Cashier');
        Sanctum::actingAs($stranger);
        $this->postJson('/api/pos/shifts/open', [
            'pos_register_id' => $this->register->id,
            'opening_float' => '0',
            'idempotency_key' => 'shift-stranger',
        ])->assertForbidden();
    }

    public function test_another_tenant_cannot_reach_this_register_or_its_sales(): void
    {
        Sanctum::actingAs($this->cashier);
        $this->openShift();
        $this->postJson('/api/pos/sales', [
            'idempotency_key' => 'sale-tenant',
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'tenders' => [['tender_type' => 'cash', 'amount' => '20.00']],
        ])->assertCreated();

        $sale = PosSale::query()->firstOrFail();
        $outsider = $this->foreignUser();

        Sanctum::actingAs($outsider);
        $this->getJson("/api/pos/sales/{$sale->id}")->assertNotFound();
        $this->postJson('/api/pos/sales', [
            'idempotency_key' => 'sale-cross',
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'tenders' => [['tender_type' => 'cash', 'amount' => '20.00']],
        ])->assertNotFound();
    }

    public function test_expected_cash_is_derived_and_the_drawer_reconciles(): void
    {
        Sanctum::actingAs($this->cashier);
        $shift = $this->openShift('50.00');

        $this->postJson('/api/pos/sales', [
            'idempotency_key' => 'sale-drawer',
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 2]],
            'tenders' => [['tender_type' => 'cash', 'amount' => '40.00', 'tendered_amount' => '50.00']],
        ])->assertCreated();

        // A cashier cannot move cash out of the drawer on their own …
        $this->postJson("/api/pos/shifts/{$shift->id}/cash-movements", [
            'idempotency_key' => 'cash-out-cashier-denied',
            'type' => 'pay_out',
            'amount' => '15.00',
            'reason' => 'Courier',
        ])->assertForbidden();

        // … a supervisor can, and the drawer expectation follows it.
        $supervisor = $this->workspaceUser('POS Supervisor');
        PosRegisterAssignment::query()->create([
            'company_id' => $this->company->id, 'pos_register_id' => $this->register->id,
            'user_id' => $supervisor->id, 'role' => PosRegisterAssignment::ROLE_SUPERVISOR,
        ]);
        Sanctum::actingAs($supervisor);
        $movement = [
            'idempotency_key' => 'cash-out-courier',
            'type' => 'pay_out',
            'amount' => '15.00',
            'reason' => 'Courier',
        ];
        $first = $this->postJson("/api/pos/shifts/{$shift->id}/cash-movements", $movement)->assertCreated();
        $second = $this->postJson("/api/pos/shifts/{$shift->id}/cash-movements", $movement)->assertOk();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, DB::table('pos_cash_movements')->count());
        Sanctum::actingAs($this->cashier);

        // 50 float + 40 cash taken − 15 paid out = 75.
        $closed = $this->postJson("/api/pos/shifts/{$shift->id}/close", [
            'counted_cash' => '75.00',
            'idempotency_key' => 'close-1',
        ])->assertOk();
        $replayed = $this->postJson("/api/pos/shifts/{$shift->id}/close", [
            'counted_cash' => '75.00',
            'idempotency_key' => 'close-1',
        ])->assertOk();

        $this->assertSame('75.0000', $closed->json('data.expected_cash'));
        $this->assertSame($closed->json('data.id'), $replayed->json('data.id'));
        $this->assertSame('0.0000', $closed->json('data.cash_variance'));
        $this->assertSame('closed', $closed->json('data.status'));
    }

    public function test_cash_tendered_cannot_be_less_than_the_amount_applied(): void
    {
        Sanctum::actingAs($this->cashier);
        $this->openShift();

        $this->postJson('/api/pos/sales', [
            'idempotency_key' => 'sale-under-tendered',
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'tenders' => [[
                'tender_type' => 'cash',
                'amount' => '20.00',
                'tendered_amount' => '10.00',
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('tenders');

        $this->assertSame(0, PosSale::query()->count());
    }

    public function test_manual_terminal_capture_requires_an_assigned_authenticated_supervisor(): void
    {
        PosRegisterPaymentMethod::query()->create([
            'company_id' => $this->company->id,
            'pos_register_id' => $this->register->id,
            'tender_type' => PosRegisterPaymentMethod::TYPE_CARD,
            'label' => 'Card',
            'provider' => 'manual_terminal',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->cashier);
        $this->openShift();

        $this->postJson('/api/pos/sales', [
            'idempotency_key' => 'card-without-attempt',
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'tenders' => [['tender_type' => 'card', 'amount' => '20.00']],
        ])->assertUnprocessable()->assertJsonValidationErrors('tenders');

        $attempt = $this->postJson('/api/pos/terminal/intent', [
            'pos_register_id' => $this->register->id,
            'amount' => '20.00',
        ])->assertCreated()->json('data');

        // The cashier cannot turn their own browser response into a capture.
        $this->postJson('/api/pos/terminal/confirm', [
            'external_reference' => $attempt['external_reference'],
            'provider' => 'manual_terminal',
            'approved' => true,
        ])->assertForbidden();

        $supervisor = $this->workspaceUser('POS Supervisor');
        Sanctum::actingAs($supervisor);

        // Permission alone is insufficient: approval is tied to this till's roster.
        $this->postJson('/api/pos/terminal/confirm', [
            'external_reference' => $attempt['external_reference'],
            'provider' => 'manual_terminal',
            'approved' => true,
        ])->assertForbidden();

        PosRegisterAssignment::query()->create([
            'company_id' => $this->company->id,
            'pos_register_id' => $this->register->id,
            'user_id' => $supervisor->id,
            'role' => PosRegisterAssignment::ROLE_SUPERVISOR,
        ]);

        $this->postJson('/api/pos/terminal/confirm', [
            'external_reference' => $attempt['external_reference'],
            'provider' => 'integrated_provider',
            'approved' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('provider');

        $this->postJson('/api/pos/terminal/confirm', [
            'external_reference' => $attempt['external_reference'],
            'provider' => 'manual_terminal',
            'approved' => true,
            'auth_code' => 'APPROVED',
        ])->assertOk();

        $captured = PosPaymentAttempt::query()->findOrFail($attempt['id']);
        $this->assertSame(PosPaymentAttempt::STATE_CAPTURED, $captured->state);
        $this->assertSame($supervisor->id, $captured->provider_response['operator_user_id']);

        $declined = $this->postJson('/api/pos/terminal/intent', [
            'pos_register_id' => $this->register->id,
            'amount' => '20.00',
        ])->assertCreated()->json('data');
        $this->postJson('/api/pos/terminal/confirm', [
            'external_reference' => $declined['external_reference'],
            'provider' => 'manual_terminal',
            'approved' => false,
            'failure_reason' => 'Declined',
        ])->assertOk();
        $this->postJson('/api/pos/terminal/confirm', [
            'external_reference' => $declined['external_reference'],
            'provider' => 'manual_terminal',
            'approved' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('external_reference');

        Sanctum::actingAs($this->cashier);
        $this->postJson('/api/pos/sales', [
            'idempotency_key' => 'card-with-captured-attempt',
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'tenders' => [[
                'tender_type' => 'card',
                'provider' => 'manual_terminal',
                'amount' => '20.00',
                'reference' => $attempt['external_reference'],
                'card_last4' => '9999',
            ]],
        ])->assertCreated();

        $this->assertNull(
            PosSale::query()->latest('id')->firstOrFail()->tenders()->firstOrFail()->card_last4,
            'Unverified card metadata from checkout must not be persisted.',
        );
    }

    public function test_card_attempt_claim_is_scoped_to_register_and_provider(): void
    {
        $attempt = PosPaymentAttempt::query()->create([
            'company_id' => $this->company->id,
            'pos_register_id' => $this->register->id,
            'provider' => 'manual_terminal',
            'external_reference' => 'capture-one',
            'state' => PosPaymentAttempt::STATE_CAPTURED,
            'amount' => '20.00',
            'attempt' => 1,
            'verified_at' => now(),
        ]);
        $otherRegister = PosRegister::query()->create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->register->warehouse_id,
            'code' => 'T2',
            'name' => 'Till 2',
            'currency' => 'EGP',
            'receipt_prefix' => 'R2',
            'is_active' => true,
            'settings' => [],
        ]);
        $service = app(PosTerminalService::class);

        foreach ([
            [$otherRegister->id, 'manual_terminal'],
            [$this->register->id, 'another_provider'],
        ] as [$registerId, $provider]) {
            try {
                $service->claimForCheckout(
                    $this->company->id,
                    $registerId,
                    $provider,
                    $attempt->external_reference,
                    '20.00',
                );
                $this->fail('A capture from another register or provider must not be claimable.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('tenders', $exception->errors());
            }
        }
    }

    public function test_customer_references_are_tenant_scoped_for_checkout_and_holds(): void
    {
        $outsider = $this->foreignUser();
        $foreignCompanyId = (int) $outsider->default_company_id;
        $foreignSalesContact = SalesContact::query()->create([
            'company_id' => $foreignCompanyId,
            'type' => 'customer',
            'name' => 'Foreign Customer',
        ]);
        $foreignCrmContact = CRMContact::query()->create([
            'company_id' => $foreignCompanyId,
            'name' => 'Foreign Lead',
        ]);

        Sanctum::actingAs($this->cashier);
        $this->openShift();

        $base = [
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'tenders' => [['tender_type' => 'cash', 'amount' => '20.00']],
        ];

        $this->postJson('/api/pos/sales', $base + [
            'idempotency_key' => 'foreign-sales-contact',
            'sales_contact_id' => $foreignSalesContact->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('sales_contact_id');

        $this->postJson('/api/pos/sales', $base + [
            'idempotency_key' => 'foreign-crm-contact',
            'crm_contact_id' => $foreignCrmContact->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('crm_contact_id');

        $this->postJson('/api/pos/holds', [
            'pos_register_id' => $this->register->id,
            'sales_contact_id' => $foreignSalesContact->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('sales_contact_id');
    }

    public function test_register_location_and_payment_accounts_are_tenant_scoped(): void
    {
        $otherWarehouse = Warehouse::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Other Store',
            'code' => 'OTHER',
        ]);
        $wrongLocation = WarehouseLocation::query()->create([
            'company_id' => $this->company->id,
            'warehouse_id' => $otherWarehouse->id,
            'name' => 'Aisle 1',
            'code' => 'A1',
        ]);
        $outsider = $this->foreignUser();
        $foreignAccount = Account::query()->create([
            'company_id' => $outsider->default_company_id,
            'code' => 'POS-FOREIGN',
            'name' => 'Foreign Cash',
            'type' => 'asset',
            'is_active' => true,
        ]);
        $administrator = $this->workspaceUser('POS Administrator');
        Sanctum::actingAs($administrator);

        $this->putJson("/api/pos/registers/{$this->register->id}", [
            'location_id' => $wrongLocation->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('location_id');

        $this->putJson("/api/pos/registers/{$this->register->id}", [
            'payment_methods' => [[
                'tender_type' => 'cash',
                'label' => 'Cash',
                'account_id' => $foreignAccount->id,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_methods');
    }

    public function test_register_payment_clearing_accounts_are_tenant_scoped(): void
    {
        $outsider = $this->foreignUser();
        $foreignAccount = Account::query()->create([
            'company_id' => $outsider->default_company_id,
            'code' => 'POS-CLEAR-FOREIGN',
            'name' => 'Foreign Clearing',
            'type' => 'asset',
            'is_active' => true,
        ]);
        $administrator = $this->workspaceUser('POS Administrator');
        Sanctum::actingAs($administrator);

        $this->putJson("/api/pos/registers/{$this->register->id}", [
            'payment_methods' => [[
                'tender_type' => 'card',
                'label' => 'Card',
                'provider' => 'manual_terminal',
                'clearing_account_id' => $foreignAccount->id,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_methods');
    }

    public function test_catalog_pricing_and_holds_require_assignment_to_the_selected_register(): void
    {
        $otherRegister = PosRegister::query()->create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->register->warehouse_id,
            'code' => 'T-UNASSIGNED',
            'name' => 'Unassigned Till',
            'currency' => 'EGP',
            'receipt_prefix' => 'UNA',
            'is_active' => true,
            'settings' => [],
        ]);
        $otherHold = PosHold::query()->create([
            'company_id' => $this->company->id,
            'pos_register_id' => $otherRegister->id,
            'cashier_id' => $this->cashier->id,
            'cart' => [['product_id' => $this->product->id, 'quantity' => '1']],
            'expires_at' => now()->addHour(),
        ]);

        Sanctum::actingAs($this->cashier);

        $this->getJson("/api/pos/catalog?pos_register_id={$otherRegister->id}")
            ->assertForbidden();
        $this->getJson("/api/pos/catalog/barcode/{$this->product->barcode}?pos_register_id={$otherRegister->id}")
            ->assertForbidden();
        $this->postJson('/api/pos/price', [
            'pos_register_id' => $otherRegister->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertForbidden();
        $this->getJson("/api/pos/holds?pos_register_id={$otherRegister->id}")
            ->assertForbidden();
        $this->deleteJson("/api/pos/holds/{$otherHold->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('pos_holds', ['id' => $otherHold->id]);
    }

    public function test_a_hold_rejects_products_that_are_not_active_in_the_workspace(): void
    {
        $this->product->forceFill(['is_active' => false])->save();
        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/pos/holds', [
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines');

        $this->assertSame(0, PosHold::query()->count());
    }

    public function test_a_supervisor_cannot_nominate_another_user_as_the_approver(): void
    {
        $actor = $this->workspaceUser('POS Supervisor');
        $otherSupervisor = $this->workspaceUser('POS Supervisor');

        $this->expectException(AuthorizationException::class);
        app(PosPolicy::class)->resolveSupervisor($actor, $otherSupervisor->id);
    }

    public function test_checkout_idempotency_identity_includes_note_and_server_owns_tax(): void
    {
        Sanctum::actingAs($this->cashier);
        $this->openShift();

        $priced = app(PosPricingService::class)->price(
            $this->cashier,
            $this->register,
            [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'tax_rate' => '99.0000',
            ]],
        );
        $this->assertSame('0.0000', $priced['tax_total']);
        $this->assertSame('20.0000', $priced['total']);

        $payload = [
            'idempotency_key' => 'complete-request-identity',
            'pos_register_id' => $this->register->id,
            'note' => 'Original note',
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'tax_rate' => '99.0000',
            ]],
            'tenders' => [['tender_type' => 'cash', 'amount' => '20.00']],
        ];

        $created = $this->postJson('/api/pos/sales', $payload)->assertCreated();
        $this->assertSame('0.0000', $created->json('data.tax_total'));
        $this->assertSame('20.0000', $created->json('data.total'));

        // Same key, different work: a conflict with the reservation already
        // held under it. 409 matches the in-progress case and the return path.
        $this->postJson('/api/pos/sales', array_replace($payload, ['note' => 'Changed note']))
            ->assertStatus(409);
        $this->assertSame(1, PosSale::query()->count());
    }

    public function test_a_completed_sale_is_fanned_out_once_through_the_transactional_outbox(): void
    {
        $endpoint = WebhookEndpoint::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Retail integration',
            'url' => 'https://erp-client.example.test/hooks',
            'secret' => 'webhook-secret',
            'events' => ['pos.sale.completed'],
            'is_active' => true,
        ]);
        Http::fake(['https://erp-client.example.test/*' => Http::response(['accepted' => true], 202)]);

        Sanctum::actingAs($this->cashier);
        $this->openShift();
        $this->postJson('/api/pos/sales', [
            'idempotency_key' => 'sale-outbox',
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'tenders' => [['tender_type' => 'cash', 'amount' => '20.00']],
        ])->assertCreated();

        $outbox = PosOutboxEvent::query()->sole();
        (new ProcessPosOutboxEvent($outbox->id))->handle(app(WebhookService::class));

        $this->assertNotNull($outbox->fresh()->processed_at);
        $delivery = $endpoint->deliveries()->sole();
        $this->assertSame('delivered', $delivery->status);
        $this->assertSame(PosSale::query()->sole()->id, $delivery->payload['data']['sale_id']);
        Http::assertSentCount(1);

        // A duplicate worker sees the processed marker and emits nothing else.
        (new ProcessPosOutboxEvent($outbox->id))->handle(app(WebhookService::class));
        $this->assertSame(1, $endpoint->deliveries()->count());
        Http::assertSentCount(1);
    }

    public function test_a_poisoned_outbox_event_is_dead_lettered_after_the_persistent_retry_limit(): void
    {
        $outbox = PosOutboxEvent::query()->create([
            'company_id' => $this->company->id,
            'event' => 'pos.unsupported',
            'subject_type' => PosSale::class,
            'subject_id' => 999999,
            'payload' => [],
            'available_at' => now(),
            'attempts' => 4,
        ]);

        (new ProcessPosOutboxEvent($outbox->id))->handle(app(WebhookService::class));

        $outbox->refresh();
        $this->assertSame(5, $outbox->attempts);
        $this->assertNotNull($outbox->failed_at);
        $this->assertNull($outbox->processed_at);
        $this->assertStringContainsString('Unsupported POS outbox event', $outbox->last_error);
    }

    private function assertJournalsBalance(): void
    {
        $unbalanced = DB::table('journal_lines')
            ->selectRaw('journal_entry_id, ROUND(SUM(debit) - SUM(credit), 4) AS diff')
            ->groupBy('journal_entry_id')
            ->havingRaw('ROUND(SUM(debit) - SUM(credit), 4) <> 0')
            ->get();

        $this->assertCount(0, $unbalanced, 'Every posted journal entry must balance.');
    }

    private function openShift(string $float = '0'): PosShift
    {
        $response = $this->postJson('/api/pos/shifts/open', [
            'pos_register_id' => $this->register->id,
            'opening_float' => $float,
            'idempotency_key' => 'shift-'.Str::random(8),
        ])->assertCreated();

        return PosShift::query()->findOrFail($response->json('data.id'));
    }

    private function buildTill(): void
    {
        $plan = SubscriptionPlan::query()->create([
            'slug' => 'pos-plan', 'name' => 'POS Plan', 'monthly_price' => 100, 'annual_price' => 1000,
            'currency' => 'EGP', 'max_companies' => 5, 'max_users' => 50, 'storage_gb' => 10,
            'api_rate_limit_per_minute' => 120, 'trial_enabled' => false, 'trial_days' => 0,
            'is_active' => true, 'sort_order' => 1,
        ]);

        // POS needs its own feature plus the modules it posts into.
        foreach (['core.pos', 'core.inventory', 'core.finance', 'core.sales'] as $slug) {
            $feature = SubscriptionFeature::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'module' => explode('.', $slug)[1], 'description' => $slug],
            );
            $plan->features()->attach($feature->id, ['enabled' => true]);
        }

        $organization = Organization::query()->create([
            'name' => 'Retail Group', 'status' => Organization::STATUS_ACTIVE,
            'timezone' => 'Africa/Cairo', 'locale' => 'en', 'currency' => 'EGP', 'settings' => [],
        ]);
        $this->company = Company::query()->create([
            'organization_id' => $organization->id, 'name' => 'Retail HQ',
            'plan' => $plan->slug, 'is_active' => true, 'settings' => [],
        ]);
        CompanySubscription::query()->create([
            'company_id' => $this->company->id, 'organization_id' => $organization->id,
            'subscription_plan_id' => $plan->id,
            'entitlements_snapshot' => app(OrganizationEntitlementService::class)->snapshot($plan),
            'status' => 'active', 'billing_cycle' => 'monthly', 'auto_renew' => false,
            'starts_at' => now(), 'current_period_started_at' => now(), 'current_period_ends_at' => now()->addMonth(),
        ]);

        // Same provisioning a real company gets on creation: chart of
        // accounts and default units. POS posts into these.
        app(FinanceSetupService::class)->provision($this->company);
        app(InventorySetupService::class)->provision($this->company);

        $warehouse = Warehouse::query()->create([
            'company_id' => $this->company->id, 'name' => 'Store', 'code' => 'ST',
        ]);
        $unitId = DB::table('units')->insertGetId([
            'company_id' => $this->company->id, 'name' => 'Each', 'symbol' => 'EA', 'precision' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->product = Product::query()->create([
            'company_id' => $this->company->id, 'unit_id' => $unitId, 'name' => 'Coffee', 'sku' => 'CF-1',
            'barcode' => '5000000000001', 'sale_price' => '20.0000', 'purchase_price' => '8.0000',
            'valuation_method' => 'average', 'is_active' => true,
        ]);
        StockBalance::query()->create([
            'company_id' => $this->company->id, 'product_id' => $this->product->id,
            'warehouse_id' => $warehouse->id, 'location_id' => null,
            'quantity' => '10.0000', 'average_cost' => '8.0000', 'reorder_point' => 0, 'reorder_quantity' => 0,
        ]);
        // Stock normally arrives through a receipt, which lays down the
        // valuation layer the issue strategy consumes. Without it the sale
        // would fail with "insufficient valuation layers".
        InventoryCostLayer::query()->create([
            'company_id' => $this->company->id, 'product_id' => $this->product->id,
            'warehouse_id' => $warehouse->id, 'location_id' => null,
            'original_quantity' => '10.0000', 'remaining_quantity' => '10.0000',
            'unit_cost' => '8.0000', 'received_at' => now(),
        ]);

        $this->register = PosRegister::query()->create([
            'company_id' => $this->company->id, 'warehouse_id' => $warehouse->id,
            'code' => 'T1', 'name' => 'Till 1', 'currency' => 'EGP', 'receipt_prefix' => 'RCP',
            'is_active' => true, 'settings' => [],
        ]);
        PosRegisterPaymentMethod::query()->create([
            'company_id' => $this->company->id, 'pos_register_id' => $this->register->id,
            'tender_type' => PosRegisterPaymentMethod::TYPE_CASH, 'label' => 'Cash', 'is_active' => true,
        ]);

        $this->cashier = $this->workspaceUser('POS Cashier');
        PosRegisterAssignment::query()->create([
            'company_id' => $this->company->id, 'pos_register_id' => $this->register->id,
            'user_id' => $this->cashier->id, 'role' => PosRegisterAssignment::ROLE_CASHIER,
        ]);
    }

    /**
     * @param  string  $role  the workspace role, which is what actually grants
     *                        permissions — membership role, not a global one.
     */
    private function workspaceUser(string $role): User
    {
        $user = User::factory()->create([
            'company_id' => $this->company->id,
            'default_company_id' => $this->company->id,
            'is_active' => true,
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $this->company->organization_id, 'user_id' => $user->id,
            'role' => OrganizationMembership::ROLE_MEMBER, 'status' => OrganizationMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
        CompanyMembership::query()->create([
            'organization_id' => $this->company->organization_id, 'company_id' => $this->company->id,
            'user_id' => $user->id, 'role_id' => Role::findByName($role, 'web')->id,
            'status' => CompanyMembership::STATUS_ACTIVE, 'joined_at' => now(),
        ]);

        return $user;
    }

    private function foreignUser(): User
    {
        $organization = Organization::query()->create([
            'name' => 'Other Group', 'status' => Organization::STATUS_ACTIVE,
            'timezone' => 'Africa/Cairo', 'locale' => 'en', 'currency' => 'EGP', 'settings' => [],
        ]);
        $company = Company::query()->create([
            'organization_id' => $organization->id, 'name' => 'Other Co',
            'plan' => 'pos-plan', 'is_active' => true, 'settings' => [],
        ]);
        // Same plan and entitlements, so this test measures tenant isolation
        // rather than tripping over a missing subscription feature.
        $plan = SubscriptionPlan::query()->where('slug', 'pos-plan')->firstOrFail();
        CompanySubscription::query()->create([
            'company_id' => $company->id, 'organization_id' => $organization->id,
            'subscription_plan_id' => $plan->id,
            'entitlements_snapshot' => app(OrganizationEntitlementService::class)->snapshot($plan),
            'status' => 'active', 'billing_cycle' => 'monthly', 'auto_renew' => false,
            'starts_at' => now(), 'current_period_started_at' => now(), 'current_period_ends_at' => now()->addMonth(),
        ]);
        $user = User::factory()->create([
            'company_id' => $company->id, 'default_company_id' => $company->id, 'is_active' => true,
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id,
            'role' => OrganizationMembership::ROLE_MEMBER, 'status' => OrganizationMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
        CompanyMembership::query()->create([
            'organization_id' => $organization->id, 'company_id' => $company->id,
            'user_id' => $user->id, 'role_id' => Role::findByName('POS Cashier', 'web')->id,
            'status' => CompanyMembership::STATUS_ACTIVE, 'joined_at' => now(),
        ]);

        return $user;
    }
}
