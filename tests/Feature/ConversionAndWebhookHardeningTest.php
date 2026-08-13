<?php

namespace Tests\Feature;

use App\Modules\Companies\Models\Company;
use App\Modules\Finance\Models\FinanceContact;
use App\Modules\Finance\Models\Invoice;
use App\Modules\MetaIntegration\Jobs\SendMetaConversionEvent;
use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\MetaIntegration\Models\MetaEventLog;
use App\Modules\MetaIntegration\Models\MetaEventMapping;
use App\Modules\MetaIntegration\Services\MetaConversionService;
use App\Modules\Platform\Models\WebhookEndpoint;
use App\Modules\Platform\Services\WebhookService;
use App\Modules\POS\Jobs\ProcessPosOutboxEvent;
use App\Modules\POS\Models\PosOutboxEvent;
use App\Modules\POS\Models\PosSale;
use App\Modules\TikTokIntegration\Jobs\SendTikTokEvent;
use App\Modules\TikTokIntegration\Models\TikTokConnection;
use App\Modules\TikTokIntegration\Models\TikTokEventLog;
use App\Modules\TikTokIntegration\Models\TikTokEventMapping;
use App\Modules\TikTokIntegration\Services\TikTokEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class ConversionAndWebhookHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dispatcher_leases_due_outbox_rows_on_the_consumed_default_queue(): void
    {
        Queue::fake();
        $company = Company::factory()->create();
        $outbox = PosOutboxEvent::query()->create([
            'company_id' => $company->id,
            'event' => 'pos.sale.completed',
            'subject_type' => PosSale::class,
            'subject_id' => 123,
            'payload' => [],
            'available_at' => now(),
        ]);

        Artisan::call('pos:dispatch-outbox');
        Artisan::call('pos:dispatch-outbox');

        Queue::assertPushed(ProcessPosOutboxEvent::class, 1);
        Queue::assertPushed(ProcessPosOutboxEvent::class, fn ($job) => $job->queue === null);
        $this->assertTrue($outbox->fresh()->available_at->isFuture());
    }

    public function test_invoice_conversions_are_tenant_safe_deduplicated_and_include_customer_value_and_currency(): void
    {
        Queue::fake();
        [$company, $invoice] = $this->invoice();
        $meta = MetaConnection::query()->create([
            'company_id' => $company->id,
            'status' => 'connected',
            'connection_method' => 'oauth',
            'access_token' => 'meta-token',
            'pixel_id' => 'pixel-1',
        ]);
        MetaEventMapping::query()->create([
            'company_id' => $company->id,
            'meta_connection_id' => $meta->id,
            'trigger_source' => 'invoice_paid',
            'meta_event_name' => 'Purchase',
            'value_field' => 'invoice.total',
            'currency_field' => 'invoice.currency',
            'is_active' => true,
        ]);
        $tiktok = TikTokConnection::query()->create([
            'company_id' => $company->id,
            'status' => 'connected',
            'connection_method' => 'oauth',
            'access_token' => 'tiktok-token',
            'events_enabled' => true,
            'pixel_code' => 'pixel-code-1',
        ]);
        TikTokEventMapping::query()->create([
            'company_id' => $company->id,
            'tiktok_connection_id' => $tiktok->id,
            'trigger_source' => 'invoice_paid',
            'event_name' => 'CompletePayment',
            'value_field' => 'invoice.total',
            'currency_field' => 'invoice.currency',
            'is_active' => true,
        ]);

        $metaLog = app(MetaConversionService::class)->recordEvent($company->id, 'invoice_paid', $invoice);
        $tiktokLog = app(TikTokEventService::class)->recordEvent($company->id, 'invoice_paid', $invoice);

        // MySQL JSON normalizes an integral 149.0 to 149; numeric equality is
        // the API contract, not the in-memory PHP scalar representation.
        $this->assertEquals(149.0, $metaLog->payload['custom_data']['value']);
        $this->assertSame('EGP', $metaLog->payload['custom_data']['currency']);
        $this->assertSame(hash('sha256', 'buyer@example.test'), $metaLog->payload['user_data']['em']);
        $this->assertEquals(149.0, $tiktokLog->payload['properties']['value']);
        $this->assertSame('EGP', $tiktokLog->payload['properties']['currency']);
        $this->assertSame(hash('sha256', 'buyer@example.test'), $tiktokLog->payload['user']['email']);

        $this->assertSame(
            $metaLog->id,
            app(MetaConversionService::class)->recordEvent($company->id, 'invoice_paid', $invoice)->id,
        );
        $this->assertSame(
            $tiktokLog->id,
            app(TikTokEventService::class)->recordEvent($company->id, 'invoice_paid', $invoice)->id,
        );
        $this->assertSame(1, MetaEventLog::query()->count());
        $this->assertSame(1, TikTokEventLog::query()->count());
        Queue::assertPushed(SendMetaConversionEvent::class, 2);
        Queue::assertPushed(SendTikTokEvent::class, 2);

        $meta->forceFill(['require_consent' => true])->save();
        Queue::fake();
        $this->assertNull(app(MetaConversionService::class)->recordEvent($company->id, 'invoice_paid', $invoice));
        Queue::assertNothingPushed();
    }

    public function test_conversion_services_reject_a_related_record_from_another_company(): void
    {
        [$company, $invoice] = $this->invoice();

        foreach ([
            fn () => app(MetaConversionService::class)->recordEvent($company->id + 9999, 'invoice_paid', $invoice),
            fn () => app(TikTokEventService::class)->recordEvent($company->id + 9999, 'invoice_paid', $invoice),
        ] as $record) {
            try {
                $record();
                $this->fail('A conversion service accepted a record from another company.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('cross company boundaries', $exception->getMessage());
            }
        }
    }

    public function test_an_administrator_retry_reuses_the_delivery_id_and_reactivates_the_endpoint(): void
    {
        $company = Company::factory()->create();
        $endpoint = WebhookEndpoint::query()->create([
            'company_id' => $company->id,
            'name' => 'Accounting listener',
            'url' => 'https://listener.example.test/hooks',
            'secret' => 'top-secret',
            'events' => ['pos.sale.completed'],
            'is_active' => false,
            'failure_count' => 5,
            'disabled_at' => now(),
        ]);
        $deliveryId = (string) Str::uuid();
        $delivery = $endpoint->deliveries()->create([
            'event' => 'pos.sale.completed',
            'delivery_id' => $deliveryId,
            'payload' => [
                'id' => $deliveryId,
                'event' => 'pos.sale.completed',
                'created_at' => now()->toIso8601String(),
                'data' => ['sale_id' => 10],
            ],
            'attempts' => 5,
            'status' => 'failed',
        ]);
        Http::fake(['https://listener.example.test/*' => Http::response(null, 204)]);

        $retried = app(WebhookService::class)->retry($delivery);

        $this->assertSame('delivered', $retried->status);
        $this->assertSame($deliveryId, $retried->delivery_id);
        $this->assertSame(1, $endpoint->deliveries()->count());
        $this->assertTrue($endpoint->fresh()->is_active);
        $this->assertNull($endpoint->fresh()->disabled_at);
        Http::assertSent(fn ($request) => $request->header('X-Manzoma-Delivery')[0] === $deliveryId);
        Http::assertSentCount(1);
    }

    /** @return array{Company, Invoice} */
    private function invoice(): array
    {
        $company = Company::factory()->create(['currency' => 'EGP']);
        $contact = FinanceContact::query()->create([
            'company_id' => $company->id,
            'type' => 'customer',
            'name' => 'Buyer Example',
            'email' => 'Buyer@Example.Test',
            'phone' => '+20 100 123 4567',
            'currency' => 'EGP',
        ]);
        $invoice = Invoice::query()->create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'type' => 'receivable',
            'number' => 'INV-OUTBOX-1',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'currency' => 'EGP',
            'exchange_rate' => 1,
            'subtotal' => '149.0000',
            'tax_total' => '0.0000',
            'total' => '149.0000',
            'paid_total' => '149.0000',
            'status' => 'paid',
        ]);

        return [$company, $invoice];
    }
}
