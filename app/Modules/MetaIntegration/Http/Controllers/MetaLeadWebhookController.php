<?php

namespace App\Modules\MetaIntegration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MetaIntegration\Jobs\ProcessMetaLeadWebhookEvent;
use App\Modules\MetaIntegration\Jobs\ProcessMetaWhatsAppWebhookEvent;
use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\MetaIntegration\Models\MetaLeadFormMapping;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MetaLeadWebhookController extends Controller
{
    public function verify(Request $request)
    {
        // PHP auto-converts dots in query-string keys to underscores, so Meta's
        // "hub.mode"/"hub.verify_token"/"hub.challenge" params arrive as hub_mode/etc.
        $providedToken = (string) $request->query('hub_verify_token');

        if ($request->query('hub_mode') === 'subscribe' && $this->isKnownVerifyToken($providedToken)) {
            return response((string) $request->query('hub_challenge'), 200);
        }

        return response('Invalid verify token', 403);
    }

    /**
     * Each tenant may run their own Meta App with its own webhook subscription,
     * so the verify token can be either the shared platform token or the
     * per-company token generated when they saved their App ID/Secret.
     */
    private function isKnownVerifyToken(string $providedToken): bool
    {
        if ($providedToken === '') {
            return false;
        }

        if (config('meta.webhook_verify_token') && hash_equals((string) config('meta.webhook_verify_token'), $providedToken)) {
            return true;
        }

        return MetaConnection::whereNotNull('webhook_verify_token')
            ->get()
            ->contains(fn (MetaConnection $connection) => hash_equals((string) $connection->webhook_verify_token, $providedToken));
    }

    public function receive(Request $request)
    {
        $signature = $request->header('X-Hub-Signature-256', '');

        if (! $this->hasValidSignature($request->getContent(), $signature)) {
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                match ($change['field'] ?? null) {
                    'leadgen' => $this->dispatchLeadgen($change['value'] ?? []),
                    'messages' => $this->dispatchWhatsAppMessages($change['value'] ?? []),
                    default => null,
                };
            }
        }

        return response()->json(['status' => 'ok'], Response::HTTP_OK);
    }

    private function dispatchLeadgen(array $value): void
    {
        if (empty($value['leadgen_id']) || empty($value['page_id']) || empty($value['form_id'])) {
            return;
        }

        ProcessMetaLeadWebhookEvent::dispatch(
            $value['page_id'],
            $value['form_id'],
            $value['leadgen_id'],
        )->onQueue('meta-events');
    }

    private function dispatchWhatsAppMessages(array $value): void
    {
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
        if (! $phoneNumberId) {
            return;
        }

        $profileName = $value['contacts'][0]['profile']['name'] ?? null;

        foreach ($value['messages'] ?? [] as $message) {
            if (empty($message['from']) || empty($message['id'])) {
                continue;
            }

            ProcessMetaWhatsAppWebhookEvent::dispatch(
                $phoneNumberId,
                $message['from'],
                $profileName,
                $message['text']['body'] ?? null,
                $message['id'],
            )->onQueue('meta-events');
        }
    }

    /**
     * Each tenant may use their own Meta App, so the signing secret for a given
     * payload depends on which company's page fired it — resolve via the first
     * page_id in the payload before falling back to the shared platform secret.
     */
    private function hasValidSignature(string $rawBody, string $signature): bool
    {
        $secrets = array_filter(array_unique([
            $this->appSecretForPayload($rawBody),
            config('meta.app_secret'),
        ]));

        foreach ($secrets as $secret) {
            $expected = 'sha256='.hash_hmac('sha256', $rawBody, (string) $secret);
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function appSecretForPayload(string $rawBody): ?string
    {
        $payload = json_decode($rawBody, true) ?? [];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if ($pageId = $change['value']['page_id'] ?? null) {
                    $mapping = MetaLeadFormMapping::with('connection')->where('page_id', $pageId)->first();
                    if ($mapping?->connection?->app_secret) {
                        return $mapping->connection->app_secret;
                    }
                }

                // WhatsApp payloads carry no page_id; the owning company is
                // identified by the receiving phone number instead.
                if ($phoneNumberId = $change['value']['metadata']['phone_number_id'] ?? null) {
                    $connection = MetaConnection::where('whatsapp_phone_number_id', $phoneNumberId)->first();
                    if ($connection?->app_secret) {
                        return $connection->app_secret;
                    }
                }
            }
        }

        return null;
    }
}
