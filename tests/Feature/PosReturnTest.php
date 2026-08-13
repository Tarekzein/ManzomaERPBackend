<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\FinanceSetupService;
use App\Modules\Inventory\Models\InventoryCostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventorySetupService;
use App\Modules\Organizations\Models\CompanyMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\POS\Models\PosRegister;
use App\Modules\POS\Models\PosRegisterAssignment;
use App\Modules\POS\Models\PosRegisterPaymentMethod;
use App\Modules\POS\Models\PosReturn;
use App\Modules\POS\Models\PosSale;
use App\Modules\POS\Models\PosShift;
use App\Modules\POS\Models\PosTender;
use App\Modules\POS\Services\PosShiftService;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionFeature;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use App\Modules\Subscriptions\Services\OrganizationEntitlementService;
use App\Support\Decimal;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * §10 gates for returns and refunds.
 */
class PosReturnTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private PosRegister $register;

    private User $cashier;

    private User $supervisor;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->buildTill();
    }

    public function test_a_partial_return_restocks_refunds_and_reverses_the_ledger(): void
    {
        $sale = $this->sell(4, '80.00');
        $line = $sale->lines->first();

        Sanctum::actingAs($this->supervisor);
        $this->openShift($this->supervisor);

        $response = $this->postJson("/api/pos/sales/{$sale->id}/returns", [
            'idempotency_key' => 'ret-1',
            'lines' => [['pos_sale_line_id' => $line->id, 'quantity' => 1]],
            'reason' => 'Damaged box',
        ])->assertCreated();

        $this->assertSame('20.0000', $response->json('data.total'));

        // Stock came back: 10 − 4 sold + 1 returned.
        $this->assertSame('7.0000', StockBalance::query()->firstOrFail()->quantity);

        // The line records what has been consumed, capping later returns.
        $this->assertSame('1.0000', $line->refresh()->returned_quantity);

        // Revenue reversed by a credit note against the sale's invoice.
        $credit = CreditNote::query()->firstOrFail();
        $this->assertSame('20.0000', $credit->amount);
        $this->assertSame($sale->invoice_id, $credit->invoice_id);

        // The refund went back on the cash tender it came in on.
        $tender = PosTender::query()->firstOrFail();
        $this->assertSame('20.0000', $tender->refunded_amount);

        // Finance metadata and allocations reverse together: 80 paid becomes
        // 60 paid + 20 credited, so the invoice remains settled at zero.
        $invoice = Invoice::query()->findOrFail($sale->invoice_id);
        $this->assertSame('60.0000', $invoice->paid_total);
        $this->assertSame('20.0000', $invoice->credited_total);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(0.0, $invoice->balance);
        $this->assertSame('-20.0000', (string) DB::table('payment_allocations')->where('amount', '<', 0)->value('amount'));

        // Returning sellable stock reverses COGS, not Accounts Payable.
        $return = PosReturn::query()->firstOrFail();
        $cogsLines = DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_lines.journal_entry_id', $return->cogs_journal_entry_id)
            ->get(['accounts.code', 'journal_lines.debit', 'journal_lines.credit'])
            ->keyBy('code');
        $this->assertSame('8.0000', (string) $cogsLines['1200']->debit);
        $this->assertSame('8.0000', (string) $cogsLines['5000']->credit);
        $this->assertArrayNotHasKey('2000', $cogsLines->all());

        $this->assertJournalsBalance();
    }

    public function test_returns_can_never_exceed_the_quantity_sold(): void
    {
        $sale = $this->sell(2, '40.00');
        $line = $sale->lines->first();

        Sanctum::actingAs($this->supervisor);
        $this->openShift($this->supervisor);

        // First return takes both units.
        $this->postJson("/api/pos/sales/{$sale->id}/returns", [
            'idempotency_key' => 'ret-a',
            'lines' => [['pos_sale_line_id' => $line->id, 'quantity' => 2]],
        ])->assertCreated();

        // A second attempt has nothing left to give.
        $this->postJson("/api/pos/sales/{$sale->id}/returns", [
            'idempotency_key' => 'ret-b',
            'lines' => [['pos_sale_line_id' => $line->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines');

        $this->assertSame('2.0000', $line->refresh()->returned_quantity);
    }

    public function test_the_final_partial_return_absorbs_all_rounding_residue(): void
    {
        $this->product->forceFill([
            'sale_price' => '1.0000',
            'purchase_price' => '0.3333',
        ])->save();
        StockBalance::query()->update(['average_cost' => '0.3333']);
        InventoryCostLayer::query()->update(['unit_cost' => '0.3333']);
        $this->register->forceFill(['settings' => ['tax' => ['default_rate' => '10']]])->save();

        Sanctum::actingAs($this->supervisor);
        $this->openShift($this->supervisor);
        $this->postJson('/api/pos/sales', [
            'idempotency_key' => 'sale-return-residue',
            'pos_register_id' => $this->register->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => 3,
                'discount_amount' => '2.0000',
            ]],
            'tenders' => [['tender_type' => 'cash', 'amount' => '1.1000']],
        ])->assertCreated();

        $sale = PosSale::query()->with('lines')->latest('id')->firstOrFail();
        $line = $sale->lines->first();

        foreach (range(1, 6) as $part) {
            $this->postJson("/api/pos/sales/{$sale->id}/returns", [
                'idempotency_key' => "ret-residue-{$part}",
                'lines' => [['pos_sale_line_id' => $line->id, 'quantity' => '0.5000']],
            ])->assertCreated();
        }

        $returns = PosReturn::query()->orderBy('id')->get();
        $last = $returns->last();

        // Five independently rounded parts consume 0.85 net, all 0.10 tax,
        // and 0.8335 cost. The final part takes the exact stored residues.
        $this->assertSame('0.1500', $last->subtotal);
        $this->assertSame('0.0000', $last->tax_total);
        $this->assertSame('0.1664', $last->cost_total);
        $this->assertSame('1.0000', Decimal::sum($returns->pluck('subtotal'))->toString());
        $this->assertSame('0.1000', Decimal::sum($returns->pluck('tax_total'))->toString());
        $this->assertSame('0.9999', Decimal::sum($returns->pluck('cost_total'))->toString());
        $this->assertSame('1.1000', Decimal::sum($returns->pluck('total'))->toString());
        $this->assertSame('1.1000', PosTender::query()->firstOrFail()->refunded_amount);
        $this->assertSame('3.0000', $line->refresh()->returned_quantity);
    }

    public function test_duplicate_sale_lines_cannot_over_return_in_one_request(): void
    {
        $sale = $this->sell(1, '20.00');
        $line = $sale->lines->first();

        Sanctum::actingAs($this->supervisor);
        $this->openShift($this->supervisor);

        $this->postJson("/api/pos/sales/{$sale->id}/returns", [
            'idempotency_key' => 'ret-duplicate-line',
            'lines' => [
                ['pos_sale_line_id' => $line->id, 'quantity' => 1],
                ['pos_sale_line_id' => $line->id, 'quantity' => 1],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines');

        $this->assertSame('0.0000', $line->refresh()->returned_quantity);
        $this->assertSame(0, PosReturn::query()->count());
    }

    public function test_duplicate_explicit_refund_tenders_are_rejected(): void
    {
        $sale = $this->sell(1, '20.00');
        $line = $sale->lines->first();
        $tender = $sale->tenders()->firstOrFail();

        Sanctum::actingAs($this->supervisor);
        $this->openShift($this->supervisor);

        $this->postJson("/api/pos/sales/{$sale->id}/returns", [
            'idempotency_key' => 'ret-duplicate-refund',
            'lines' => [['pos_sale_line_id' => $line->id, 'quantity' => 1]],
            'refunds' => [
                ['pos_tender_id' => $tender->id, 'amount' => '10.00'],
                ['pos_tender_id' => $tender->id, 'amount' => '10.00'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('refunds');

        $this->assertSame('0.0000', $line->refresh()->returned_quantity);
        $this->assertSame(0, PosReturn::query()->count());
    }

    public function test_an_automatic_refund_fails_atomically_when_tenders_cannot_cover_it(): void
    {
        $sale = $this->sell(1, '20.00');
        $line = $sale->lines->first();
        $sale->tenders()->update(['refunded_amount' => '20.0000']);

        Sanctum::actingAs($this->supervisor);
        $this->openShift($this->supervisor);

        $this->postJson("/api/pos/sales/{$sale->id}/returns", [
            'idempotency_key' => 'ret-no-refundable-value',
            'lines' => [['pos_sale_line_id' => $line->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('refunds');

        $this->assertSame('0.0000', $line->refresh()->returned_quantity);
        $this->assertSame(0, PosReturn::query()->count());
        $this->assertSame(0, CreditNote::query()->count());
    }

    public function test_a_damaged_return_refunds_without_returning_sellable_stock(): void
    {
        $sale = $this->sell(3, '60.00');
        $line = $sale->lines->first();

        Sanctum::actingAs($this->supervisor);
        $this->openShift($this->supervisor);

        $this->postJson("/api/pos/sales/{$sale->id}/returns", [
            'idempotency_key' => 'ret-damaged',
            'lines' => [[
                'pos_sale_line_id' => $line->id,
                'quantity' => 1,
                'disposition' => 'damaged',
            ]],
        ])->assertCreated();

        // Money returned, but the shelf count is unchanged at 10 − 3.
        $this->assertSame('7.0000', StockBalance::query()->firstOrFail()->quantity);
        $this->assertSame('20.0000', PosTender::query()->firstOrFail()->refunded_amount);
    }

    public function test_a_cashier_cannot_return_without_a_supervisor(): void
    {
        $sale = $this->sell(1, '20.00');

        Sanctum::actingAs($this->cashier);
        $this->postJson("/api/pos/sales/{$sale->id}/returns", [
            'idempotency_key' => 'ret-denied',
            'lines' => [['pos_sale_line_id' => $sale->lines->first()->id, 'quantity' => 1]],
        ])->assertForbidden();

        $this->assertSame('0.0000', $sale->lines->first()->refresh()->returned_quantity);
    }

    public function test_a_retried_return_does_not_refund_twice(): void
    {
        $sale = $this->sell(2, '40.00');
        $line = $sale->lines->first();

        Sanctum::actingAs($this->supervisor);
        $this->openShift($this->supervisor);

        $payload = [
            'idempotency_key' => 'ret-retry',
            'lines' => [['pos_sale_line_id' => $line->id, 'quantity' => 1]],
        ];

        $first = $this->postJson("/api/pos/sales/{$sale->id}/returns", $payload)->assertCreated();
        DB::table('pos_shifts')
            ->where('cashier_id', $this->supervisor->id)
            ->where('status', PosShift::STATUS_OPEN)
            ->update(['status' => PosShift::STATUS_CLOSED, 'closed_at' => now()]);
        $second = $this->postJson("/api/pos/sales/{$sale->id}/returns", $payload)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame('1.0000', $line->refresh()->returned_quantity);
        $this->assertSame('20.0000', PosTender::query()->firstOrFail()->refunded_amount);
    }

    public function test_a_return_idempotency_key_cannot_be_reused_with_a_changed_command(): void
    {
        $sale = $this->sell(2, '40.00');
        $line = $sale->lines->first();

        Sanctum::actingAs($this->supervisor);
        $this->openShift($this->supervisor);

        $payload = [
            'idempotency_key' => 'ret-command-conflict',
            'reason' => 'First reason',
            'lines' => [['pos_sale_line_id' => $line->id, 'quantity' => 1]],
        ];
        $this->postJson("/api/pos/sales/{$sale->id}/returns", $payload)->assertCreated();

        $this->postJson("/api/pos/sales/{$sale->id}/returns", [
            ...$payload,
            'reason' => 'Changed reason',
        ])->assertStatus(409);

        $this->assertSame(1, PosReturn::query()->count());
        $this->assertSame('1.0000', $line->refresh()->returned_quantity);
    }

    public function test_voiding_a_sale_reverses_everything_and_keeps_the_record(): void
    {
        $sale = $this->sell(2, '40.00');

        Sanctum::actingAs($this->supervisor);
        $this->openShift($this->supervisor);

        $payload = [
            'idempotency_key' => 'void-1',
            'reason' => 'Customer changed their mind',
        ];
        $first = $this->postJson("/api/pos/sales/{$sale->id}/void", $payload)->assertCreated();
        $second = $this->postJson("/api/pos/sales/{$sale->id}/void", $payload)->assertOk();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        $sale->refresh();
        $this->assertSame(PosSale::STATUS_VOIDED, $sale->status);
        $this->assertNotNull($sale->voided_at);

        // Stock fully back, and the sale itself still exists.
        $this->assertSame('10.0000', StockBalance::query()->firstOrFail()->quantity);
        $this->assertSame(1, PosSale::query()->count());
        $this->assertSame(1, PosReturn::query()->count());
        $this->assertSame(1, CreditNote::query()->count());
        $this->assertSame(1, DB::table('pos_refunds')->count());

        // Voided originals remain in gross audit figures and their compensating
        // return nets revenue, stock cost and product quantity to zero.
        $summary = $this->getJson('/api/pos/reports/summary')->assertOk()->json('data');
        $this->assertSame(1, $summary['sale_count']);
        $this->assertSame('40.0000', $summary['gross_sales']);
        $this->assertSame('40.0000', $summary['returns']);
        $this->assertSame('0.0000', $summary['net_sales']);
        $this->assertSame('0.0000', $summary['cost_of_sales']);
        $products = $this->getJson('/api/pos/reports/products')->assertOk()->json('data.products');
        $this->assertSame('0.0000', $products[0]['quantity']);
        $this->assertSame('2.0000', $products[0]['returned_quantity']);
        $this->assertJournalsBalance();
    }

    public function test_reports_reconcile_with_the_sales_behind_them(): void
    {
        $sale = $this->sell(3, '60.00');

        Sanctum::actingAs($this->supervisor);
        $this->openShift($this->supervisor);
        $this->postJson("/api/pos/sales/{$sale->id}/returns", [
            'idempotency_key' => 'ret-report',
            'lines' => [['pos_sale_line_id' => $sale->lines->first()->id, 'quantity' => 1]],
        ])->assertCreated();

        $summary = $this->getJson('/api/pos/reports/summary')->assertOk()->json('data');
        $this->assertSame(1, $summary['sale_count']);
        $this->assertSame('60.0000', $summary['gross_sales']);
        $this->assertSame('20.0000', $summary['returns']);
        $this->assertSame('40.0000', $summary['net_sales']);
        // One unit came back into sellable inventory: net COGS is 2 × 8.
        $this->assertSame('16.0000', $summary['cost_of_sales']);
        $this->assertSame('24.0000', $summary['gross_margin']);

        $tenders = $this->getJson('/api/pos/reports/tenders')->assertOk()->json('data');
        $this->assertSame('cash', $tenders['tenders'][0]['name']);
        // Captured 60 less the 20 refunded.
        $this->assertSame('40.0000', $tenders['total']);

        $products = $this->getJson('/api/pos/reports/products')->assertOk()->json('data.products');
        $this->assertSame('Coffee', $products[0]['name']);
        $this->assertSame('2.0000', $products[0]['quantity']);
        $this->assertSame('1.0000', $products[0]['returned_quantity']);
        $this->assertSame('40.0000', $products[0]['value']);
        $this->assertSame('16.0000', $products[0]['cost']);
        $this->assertSame('24.0000', $products[0]['margin']);

        $shifts = $this->getJson('/api/pos/reports/shifts')->assertOk()->json('data');
        $this->assertNotEmpty($shifts['shifts']);
    }

    public function test_tax_reports_reverse_tax_from_completed_returns(): void
    {
        $this->register->forceFill(['settings' => ['tax' => ['default_rate' => '10']]])->save();
        $sale = $this->sell(3, '66.00');

        Sanctum::actingAs($this->supervisor);
        $this->openShift($this->supervisor);
        $this->postJson("/api/pos/sales/{$sale->id}/returns", [
            'idempotency_key' => 'ret-tax-report',
            'lines' => [['pos_sale_line_id' => $sale->lines->first()->id, 'quantity' => 1]],
        ])->assertCreated();

        $summary = $this->getJson('/api/pos/reports/summary')->assertOk()->json('data');
        $this->assertSame('4.0000', $summary['tax']);

        $taxes = $this->getJson('/api/pos/reports/taxes')->assertOk()->json('data');
        $this->assertSame('10.0000', $taxes['rates'][0]['name']);
        $this->assertSame('40.0000', $taxes['rates'][0]['taxable']);
        $this->assertSame('4.0000', $taxes['rates'][0]['value']);
        $this->assertSame('4.0000', $taxes['total']);
    }

    public function test_cash_refunds_and_return_totals_belong_to_the_processing_shift(): void
    {
        $sale = $this->sell(3, '60.00');
        $saleShift = PosShift::query()->findOrFail($sale->pos_shift_id);

        Sanctum::actingAs($this->supervisor);
        $this->openShift($this->supervisor, '50.00');
        $returnShift = PosShift::query()
            ->where('cashier_id', $this->supervisor->id)
            ->where('status', PosShift::STATUS_OPEN)
            ->firstOrFail();

        $returnPayload = [
            'idempotency_key' => 'ret-next-shift',
            'lines' => [['pos_sale_line_id' => $sale->lines->first()->id, 'quantity' => 1]],
        ];
        $this->postJson("/api/pos/sales/{$sale->id}/returns", $returnPayload)->assertCreated();

        $current = $this->getJson('/api/pos/shifts/current')->assertOk()->json('data');
        $this->assertSame('30.0000', $current['expected_cash']);
        $this->assertSame('20.0000', $current['returns_total']);

        $liveShift = $returnShift->refresh();
        $this->assertSame('30.0000', $liveShift->expected_cash);
        $this->assertSame('20.0000', $liveShift->returns_total);

        $shifts = app(PosShiftService::class);
        $this->assertSame('60.0000', $shifts->expectedCash($saleShift)->toString());
        $this->assertSame('30.0000', $shifts->expectedCash($returnShift)->toString());

        $closed = $this->postJson("/api/pos/shifts/{$returnShift->id}/close", [
            'counted_cash' => '30.00',
            'idempotency_key' => 'close-return-shift',
        ])->assertOk()->json('data');
        $this->assertSame('0.0000', $closed['sales_total']);
        $this->assertSame('20.0000', $closed['returns_total']);
    }

    public function test_zero_variance_threshold_requires_supervisor_for_any_nonzero_variance(): void
    {
        Sanctum::actingAs($this->cashier);
        $this->openShift($this->cashier);
        $shift = PosShift::query()
            ->where('cashier_id', $this->cashier->id)
            ->where('status', PosShift::STATUS_OPEN)
            ->firstOrFail();

        $this->postJson("/api/pos/shifts/{$shift->id}/close", [
            'counted_cash' => '1.00',
            'idempotency_key' => 'close-zero-threshold-cashier',
        ])->assertForbidden();
        $this->assertSame(PosShift::STATUS_OPEN, $shift->refresh()->status);

        Sanctum::actingAs($this->supervisor);
        $closed = $this->postJson("/api/pos/shifts/{$shift->id}/close", [
            'counted_cash' => '1.00',
            'idempotency_key' => 'close-zero-threshold-supervisor',
        ])->assertOk()->json('data');
        $this->assertSame('1.0000', $closed['cash_variance']);
        $this->assertSame($this->supervisor->id, $closed['variance_approved_by']);
    }

    public function test_cash_movement_rechecks_the_locked_shift_before_writing(): void
    {
        Sanctum::actingAs($this->supervisor);
        $this->openShift($this->supervisor);
        $staleShift = PosShift::query()
            ->where('cashier_id', $this->supervisor->id)
            ->where('status', PosShift::STATUS_OPEN)
            ->firstOrFail();

        // Simulate another request closing the shift after route binding read it.
        DB::table('pos_shifts')->where('id', $staleShift->id)->update([
            'status' => PosShift::STATUS_CLOSED,
            'closed_at' => now(),
        ]);

        try {
            app(PosShiftService::class)->recordCashMovement($this->supervisor, $staleShift, [
                'idempotency_key' => 'cash-on-stale-shift',
                'type' => 'pay_in',
                'amount' => '5.00',
                'reason' => 'Late float',
            ]);
            $this->fail('A stale open model must not allow movement on a closed shift.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('shift', $exception->errors());
        }

        $this->assertSame(0, DB::table('pos_cash_movements')->count());
    }

    public function test_return_postings_keep_the_sale_currency(): void
    {
        $this->register->forceFill(['currency' => 'USD'])->save();
        $sale = $this->sell(1, '20.00');

        Sanctum::actingAs($this->supervisor);
        $this->openShift($this->supervisor, '20.00');
        $returnId = $this->postJson("/api/pos/sales/{$sale->id}/returns", [
            'idempotency_key' => 'ret-usd',
            'lines' => [['pos_sale_line_id' => $sale->lines->first()->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');

        $return = PosReturn::query()->findOrFail($returnId);
        $creditEntryId = DB::table('credit_notes')->where('id', $return->credit_note_id)->value('journal_entry_id');
        $refundEntryIds = DB::table('pos_refunds')
            ->join('payments', 'payments.id', '=', 'pos_refunds.payment_id')
            ->where('pos_refunds.pos_return_id', $return->id)
            ->pluck('payments.journal_entry_id');
        $entryIds = collect([$return->cogs_journal_entry_id, $creditEntryId])
            ->merge($refundEntryIds)
            ->filter()
            ->unique();

        $this->assertNotEmpty($entryIds);
        $this->assertSame(
            ['USD'],
            DB::table('journal_lines')
                ->whereIn('journal_entry_id', $entryIds)
                ->distinct()
                ->orderBy('currency')
                ->pluck('currency')
                ->all(),
        );
    }

    public function test_a_card_tender_cannot_be_spent_without_a_verified_terminal_capture(): void
    {
        // Enable card on this register.
        PosRegisterPaymentMethod::query()->create([
            'company_id' => $this->company->id, 'pos_register_id' => $this->register->id,
            'tender_type' => 'card', 'label' => 'Card', 'is_active' => true, 'provider' => 'manual_terminal',
        ]);

        Sanctum::actingAs($this->cashier);
        $this->openShift($this->cashier);

        // An invented reference is refused.
        $this->postJson('/api/pos/sales', [
            'idempotency_key' => 'card-forged',
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'tenders' => [['tender_type' => 'card', 'amount' => '20.00', 'reference' => 'made-up']],
        ])->assertUnprocessable()->assertJsonValidationErrors('tenders');

        // An attempt that exists but was never approved is refused too.
        $intent = $this->postJson('/api/pos/terminal/intent', [
            'pos_register_id' => $this->register->id,
            'amount' => '20.00',
        ])->assertCreated()->json('data');

        $this->postJson('/api/pos/sales', [
            'idempotency_key' => 'card-pending',
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'tenders' => [['tender_type' => 'card', 'amount' => '20.00', 'reference' => $intent['external_reference']]],
        ])->assertUnprocessable()->assertJsonValidationErrors('tenders');

        // Manual operator confirmation requires an authenticated supervisor.
        Sanctum::actingAs($this->supervisor);
        $this->postJson('/api/pos/terminal/confirm', [
            'external_reference' => $intent['external_reference'],
            'provider' => 'manual_terminal',
            'approved' => true,
            'auth_code' => 'A1B2C3',
            'last4' => '4242',
        ])->assertOk();

        Sanctum::actingAs($this->cashier);
        $this->postJson('/api/pos/sales', [
            'idempotency_key' => 'card-ok',
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'tenders' => [['tender_type' => 'card', 'amount' => '20.00', 'reference' => $intent['external_reference'], 'card_last4' => '4242']],
        ])->assertCreated();

        // And it cannot be spent a second time on another basket.
        $this->postJson('/api/pos/sales', [
            'idempotency_key' => 'card-reuse',
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'tenders' => [['tender_type' => 'card', 'amount' => '20.00', 'reference' => $intent['external_reference']]],
        ])->assertUnprocessable()->assertJsonValidationErrors('tenders');
    }

    public function test_non_cash_returns_wait_for_a_real_provider_refund(): void
    {
        PosRegisterPaymentMethod::query()->create([
            'company_id' => $this->company->id,
            'pos_register_id' => $this->register->id,
            'tender_type' => PosRegisterPaymentMethod::TYPE_CARD,
            'label' => 'Card',
            'is_active' => true,
            'provider' => 'manual_terminal',
        ]);

        Sanctum::actingAs($this->cashier);
        $this->openShift($this->cashier);
        $intent = $this->postJson('/api/pos/terminal/intent', [
            'pos_register_id' => $this->register->id,
            'amount' => '20.00',
        ])->assertCreated()->json('data');

        Sanctum::actingAs($this->supervisor);
        $this->postJson('/api/pos/terminal/confirm', [
            'external_reference' => $intent['external_reference'],
            'provider' => 'manual_terminal',
            'approved' => true,
        ])->assertOk();

        Sanctum::actingAs($this->cashier);
        $this->postJson('/api/pos/sales', [
            'idempotency_key' => 'card-sale-for-refund',
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'tenders' => [[
                'tender_type' => PosRegisterPaymentMethod::TYPE_CARD,
                'amount' => '20.00',
                'reference' => $intent['external_reference'],
            ]],
        ])->assertCreated();
        $sale = PosSale::query()->with('lines')->latest('id')->firstOrFail();

        Sanctum::actingAs($this->supervisor);
        $this->openShift($this->supervisor);
        $this->postJson("/api/pos/sales/{$sale->id}/returns", [
            'idempotency_key' => 'card-return-needs-provider',
            'lines' => [['pos_sale_line_id' => $sale->lines->first()->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('refunds');

        $this->assertSame(0, PosReturn::query()->count());
        $this->assertSame(0, DB::table('pos_refunds')->count());
        $this->assertSame('9.0000', StockBalance::query()->firstOrFail()->quantity);
    }

    public function test_a_held_cart_is_stored_without_prices_and_can_be_removed(): void
    {
        Sanctum::actingAs($this->cashier);

        $hold = $this->postJson('/api/pos/holds', [
            'pos_register_id' => $this->register->id,
            'label' => 'Table 4',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ])->assertCreated()->json('data');

        // Only product and quantity are kept: a resumed cart is repriced.
        $this->assertSame([['product_id' => $this->product->id, 'quantity' => '2', 'discount_percent' => null]], $hold['cart']);

        $this->getJson("/api/pos/holds?pos_register_id={$this->register->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->deleteJson("/api/pos/holds/{$hold['id']}")->assertOk();
        $this->getJson("/api/pos/holds?pos_register_id={$this->register->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
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

    private function sell(int $quantity, string $amount): PosSale
    {
        Sanctum::actingAs($this->cashier);
        $this->openShift($this->cashier);

        $this->postJson('/api/pos/sales', [
            'idempotency_key' => 'sale-'.Str::random(8),
            'pos_register_id' => $this->register->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => $quantity]],
            'tenders' => [['tender_type' => 'cash', 'amount' => $amount]],
        ])->assertCreated();

        return PosSale::query()->with('lines')->latest('id')->firstOrFail();
    }

    private function openShift(User $user, string $float = '0'): void
    {
        $existing = DB::table('pos_shifts')
            ->where('cashier_id', $user->getKey())
            ->where('status', 'open')
            ->exists();

        if ($existing) {
            return;
        }

        // Only one shift may be open per register, so hand it over cleanly.
        DB::table('pos_shifts')
            ->where('pos_register_id', $this->register->id)
            ->where('status', 'open')
            ->update(['status' => 'closed', 'closed_at' => now()]);

        $this->postJson('/api/pos/shifts/open', [
            'pos_register_id' => $this->register->id,
            'opening_float' => $float,
            'idempotency_key' => 'shift-'.Str::random(8),
        ])->assertCreated();
    }

    private function buildTill(): void
    {
        $plan = SubscriptionPlan::query()->create([
            'slug' => 'pos-plan', 'name' => 'POS Plan', 'monthly_price' => 100, 'annual_price' => 1000,
            'currency' => 'EGP', 'max_companies' => 5, 'max_users' => 50, 'storage_gb' => 10,
            'api_rate_limit_per_minute' => 120, 'trial_enabled' => false, 'trial_days' => 0,
            'is_active' => true, 'sort_order' => 1,
        ]);
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

        app(FinanceSetupService::class)->provision($this->company);
        app(InventorySetupService::class)->provision($this->company);

        $warehouse = Warehouse::query()->create([
            'company_id' => $this->company->id, 'name' => 'Store', 'code' => 'ST',
        ]);
        $unitId = DB::table('units')->insertGetId([
            'company_id' => $this->company->id, 'name' => 'Each', 'symbol' => 'EA2', 'precision' => 2,
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
        $this->supervisor = $this->workspaceUser('POS Supervisor');

        foreach ([[$this->cashier, 'cashier'], [$this->supervisor, 'supervisor']] as [$user, $role]) {
            PosRegisterAssignment::query()->create([
                'company_id' => $this->company->id, 'pos_register_id' => $this->register->id,
                'user_id' => $user->id, 'role' => $role,
            ]);
        }
    }

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
}
