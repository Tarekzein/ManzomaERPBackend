<?php

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Models\WebhookDelivery;
use App\Modules\Platform\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WebhookService
{
    public function dispatch(int $companyId, string $event, array $payload): void
    {
        WebhookEndpoint::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint) => in_array('*', $endpoint->events, true) || in_array($event, $endpoint->events, true))
            ->each(fn (WebhookEndpoint $endpoint) => $this->deliver($endpoint, $event, $payload));
    }

    public function deliver(WebhookEndpoint $endpoint, string $event, array $payload): WebhookDelivery
    {
        $deliveryId = (string) Str::uuid();
        $body = ['id' => $deliveryId, 'event' => $event, 'created_at' => now()->toIso8601String(), 'data' => $payload];
        $delivery = $endpoint->deliveries()->create([
            'event' => $event,
            'delivery_id' => $deliveryId,
            'payload' => $body,
            'attempts' => 1,
            'status' => 'pending',
        ]);

        try {
            $json = json_encode($body, JSON_THROW_ON_ERROR);
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-Manzoma-Delivery' => $deliveryId,
                    'X-Manzoma-Event' => $event,
                    'X-Manzoma-Signature' => hash_hmac('sha256', $json, $endpoint->secret),
                ])
                ->withBody($json, 'application/json')
                ->post($endpoint->url);

            $success = $response->successful();
            $delivery->update([
                'status' => $success ? 'delivered' : 'failed',
                'response_status' => $response->status(),
                'response_body' => mb_substr($response->body(), 0, 5000),
                'delivered_at' => $success ? now() : null,
                'next_attempt_at' => $success ? null : now()->addMinutes(5),
            ]);
            $this->updateEndpoint($endpoint, $success);
        } catch (\Throwable $exception) {
            $delivery->update([
                'status' => 'failed',
                'response_body' => mb_substr($exception->getMessage(), 0, 5000),
                'next_attempt_at' => now()->addMinutes(5),
            ]);
            $this->updateEndpoint($endpoint, false);
        }

        return $delivery->refresh();
    }

    private function updateEndpoint(WebhookEndpoint $endpoint, bool $success): void
    {
        if ($success) {
            $endpoint->update(['failure_count' => 0, 'last_delivered_at' => now()]);

            return;
        }

        $failures = $endpoint->failure_count + 1;
        $endpoint->update([
            'failure_count' => $failures,
            'is_active' => $failures < 5,
            'disabled_at' => $failures >= 5 ? now() : null,
        ]);
    }
}
