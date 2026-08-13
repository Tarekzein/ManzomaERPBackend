<?php

namespace Tests\Feature;

use App\Modules\Platform\Models\CommandReceipt;
use App\Modules\Platform\Services\AuditService;
use App\Modules\Platform\Services\IdempotencyService;
use App\Support\ConflictException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The guarantees POS checkout is built on: run-once commands, a stock balance
 * that cannot split, and an audit trail that never records a secret.
 */
class CheckoutHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_retried_command_replays_the_first_result_instead_of_running_again(): void
    {
        $companyId = $this->company();
        $service = app(IdempotencyService::class);
        $runs = 0;

        $body = function () use (&$runs) {
            $runs++;

            return ['sale_id' => 99];
        };
        $run = function () use ($service, $companyId, $body) {
            return $service->execute(
                $companyId,
                'pos.checkout',
                'key-1',
                ['cart' => [['product' => 1, 'qty' => 2]]],
                $body,
                fn (array $result) => $result,
            );
        };

        $first = $run();
        $second = $run();

        $this->assertSame(1, $runs, 'The command body must run exactly once.');
        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $this->assertSame(['sale_id' => 99], $second['response']);
    }

    public function test_key_order_does_not_change_the_request_identity(): void
    {
        $companyId = $this->company();
        $service = app(IdempotencyService::class);
        $runs = 0;
        $body = function () use (&$runs) {
            $runs++;

            return ['ok' => true];
        };

        $service->execute($companyId, 'pos.checkout', 'key-2', ['b' => 2, 'a' => 1], $body, fn ($r) => $r);
        $replay = $service->execute($companyId, 'pos.checkout', 'key-2', ['a' => 1, 'b' => 2], $body, fn ($r) => $r);

        $this->assertSame(1, $runs);
        $this->assertTrue($replay['replayed']);
    }

    public function test_reusing_a_key_for_a_different_payload_is_rejected(): void
    {
        $companyId = $this->company();
        $service = app(IdempotencyService::class);

        $service->execute($companyId, 'pos.checkout', 'key-3', ['total' => 10], fn () => ['id' => 1], fn ($r) => $r);

        // Reusing a key for different work conflicts with the reservation
        // already held under it — 409, not a validation error.
        $this->expectException(ConflictException::class);
        $service->execute($companyId, 'pos.checkout', 'key-3', ['total' => 999], fn () => ['id' => 2], fn ($r) => $r);
    }

    public function test_a_failed_command_releases_its_key_so_a_real_retry_can_succeed(): void
    {
        $companyId = $this->company();
        $service = app(IdempotencyService::class);

        try {
            $service->execute($companyId, 'pos.checkout', 'key-4', ['x' => 1], function () {
                throw new RuntimeException('card declined');
            });
            $this->fail('The command exception should propagate.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertDatabaseMissing('command_receipts', ['idempotency_key' => 'key-4']);

        $retry = $service->execute($companyId, 'pos.checkout', 'key-4', ['x' => 1], fn () => ['id' => 7], fn ($r) => $r);
        $this->assertFalse($retry['replayed']);
        $this->assertSame(['id' => 7], $retry['response']);
    }

    public function test_a_command_still_in_flight_is_a_conflict_not_a_second_sale(): void
    {
        $companyId = $this->company();
        CommandReceipt::query()->create([
            'company_id' => $companyId,
            'command' => 'pos.checkout',
            'idempotency_key' => 'key-5',
            'request_hash' => hash('sha256', json_encode(['x' => 1])),
            'claim_token' => '11111111-1111-4111-8111-111111111111',
            'status' => CommandReceipt::STATUS_IN_PROGRESS,
        ]);

        $this->expectException(ConflictException::class);
        app(IdempotencyService::class)->execute(
            $companyId,
            'pos.checkout',
            'key-5',
            ['x' => 1],
            fn () => $this->fail('The command must not run while one is in flight.'),
        );
    }

    public function test_an_abandoned_claim_can_be_recovered_without_leaving_partial_business_writes(): void
    {
        $companyId = $this->company();
        $receipt = CommandReceipt::query()->create([
            'company_id' => $companyId,
            'command' => 'pos.checkout',
            'idempotency_key' => 'stale-key',
            'request_hash' => hash('sha256', json_encode(['x' => 1])),
            'claim_token' => '22222222-2222-4222-8222-222222222222',
            'status' => CommandReceipt::STATUS_IN_PROGRESS,
        ]);
        DB::table('command_receipts')->where('id', $receipt->id)->update([
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        $outcome = app(IdempotencyService::class)->execute(
            $companyId,
            'pos.checkout',
            'stale-key',
            ['x' => 1],
            fn () => ['sale_id' => 42],
            fn (array $result) => $result,
        );

        $this->assertFalse($outcome['replayed']);
        $this->assertSame(['sale_id' => 42], $outcome['response']);
        $this->assertDatabaseHas('command_receipts', [
            'idempotency_key' => 'stale-key',
            'status' => CommandReceipt::STATUS_COMPLETED,
        ]);
    }

    public function test_a_stale_worker_cannot_complete_or_delete_the_replacement_claim(): void
    {
        $companyId = $this->company();
        $oldToken = '33333333-3333-4333-8333-333333333333';
        $hash = hash('sha256', json_encode(['x' => 1], JSON_THROW_ON_ERROR));
        $receipt = CommandReceipt::query()->create([
            'company_id' => $companyId,
            'command' => 'pos.checkout',
            'idempotency_key' => 'fenced-stale-key',
            'request_hash' => $hash,
            'claim_token' => $oldToken,
            'status' => CommandReceipt::STATUS_IN_PROGRESS,
        ]);
        DB::table('command_receipts')->where('id', $receipt->id)->update([
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        $service = app(IdempotencyService::class);
        $reflection = new \ReflectionClass($service);
        $claim = $reflection->getMethod('claim')->invoke(
            $service,
            $companyId,
            'pos.checkout',
            'fenced-stale-key',
            $hash,
            null,
        );

        $this->assertFalse($claim['replayed']);
        $this->assertSame($receipt->id, $claim['receipt_id']);
        $this->assertNotSame($oldToken, $claim['claim_token']);

        $staleCompletion = $reflection->getMethod('completeClaim')->invoke(
            $service,
            $receipt->id,
            $oldToken,
            ['sale_id' => 1],
            ['sale_id' => 1],
        );
        $staleCleanup = $reflection->getMethod('releaseClaim')->invoke($service, $receipt->id, $oldToken);

        $this->assertSame(0, $staleCompletion);
        $this->assertSame(0, $staleCleanup);
        $this->assertDatabaseHas('command_receipts', [
            'id' => $receipt->id,
            'claim_token' => $claim['claim_token'],
            'status' => CommandReceipt::STATUS_IN_PROGRESS,
        ]);

        $replacementCompletion = $reflection->getMethod('completeClaim')->invoke(
            $service,
            $receipt->id,
            $claim['claim_token'],
            ['sale_id' => 2],
            ['sale_id' => 2],
        );

        $this->assertSame(1, $replacementCompletion);
        $this->assertDatabaseHas('command_receipts', [
            'id' => $receipt->id,
            'claim_token' => $claim['claim_token'],
            'status' => CommandReceipt::STATUS_COMPLETED,
        ]);
    }

    public function test_the_same_key_in_another_company_is_a_different_command(): void
    {
        $first = $this->company('Alpha');
        $second = $this->company('Beta');
        $service = app(IdempotencyService::class);
        $runs = 0;
        $body = function () use (&$runs) {
            $runs++;

            return ['ok' => true];
        };

        $service->execute($first, 'pos.checkout', 'shared-key', ['x' => 1], $body, fn ($r) => $r);
        $service->execute($second, 'pos.checkout', 'shared-key', ['x' => 1], $body, fn ($r) => $r);

        $this->assertSame(2, $runs, 'Tenants must not share an idempotency namespace.');
    }

    public function test_warehouse_level_stock_balances_cannot_duplicate(): void
    {
        $companyId = $this->company();
        $unitId = DB::table('units')->insertGetId([
            'company_id' => $companyId, 'name' => 'Each', 'symbol' => 'EA', 'precision' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = DB::table('products')->insertGetId([
            'company_id' => $companyId, 'unit_id' => $unitId, 'name' => 'Widget', 'sku' => 'W-1',
            'sale_price' => 10, 'purchase_price' => 5, 'valuation_method' => 'average',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $warehouseId = DB::table('warehouses')->insertGetId([
            'company_id' => $companyId, 'name' => 'Main', 'code' => 'MAIN',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = fn () => [
            'company_id' => $companyId, 'product_id' => $productId, 'warehouse_id' => $warehouseId,
            'location_id' => null, 'quantity' => 5, 'average_cost' => 5,
            'reorder_point' => 0, 'reorder_quantity' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ];

        DB::table('stock_balances')->insert($row());

        // Before the generated-column index this second insert succeeded,
        // because MySQL treats each NULL location as distinct — splitting the
        // product's stock across two rows.
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('stock_balances')->insert($row());
    }

    public function test_audit_redaction_reaches_nested_and_json_encoded_secrets(): void
    {
        $sanitize = (new \ReflectionClass(AuditService::class))->getMethod('sanitize');
        $sanitize->setAccessible(true);
        $service = app(AuditService::class);

        $clean = $sanitize->invoke($service, [
            'name' => 'Register 1',
            'password' => 'hunter2',
            'settings' => [
                'provider' => ['api_key' => 'sk_live_123', 'label' => 'Terminal A'],
                'card_number' => '4111111111111111',
            ],
            'payload' => json_encode(['authorization' => 'Bearer abc', 'amount' => 25]),
        ]);

        $this->assertSame('Register 1', $clean['name']);
        $this->assertSame('[redacted]', $clean['password']);
        $this->assertSame('[redacted]', $clean['settings']['provider']['api_key']);
        $this->assertSame('Terminal A', $clean['settings']['provider']['label'], 'Harmless siblings survive.');
        $this->assertSame('[redacted]', $clean['settings']['card_number']);

        $payload = json_decode($clean['payload'], true);
        $this->assertSame('[redacted]', $payload['authorization']);
        $this->assertSame(25, $payload['amount']);
    }

    private function company(string $name = 'Acme'): int
    {
        return DB::table('companies')->insertGetId([
            'name' => $name.' '.uniqid(),
            'slug' => strtolower($name).'-'.uniqid(),
            'plan' => 'basic',
            'is_active' => true,
            'settings' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
