<?php

namespace App\Modules\MetaIntegration\Services;

use App\Modules\CRM\Models\CRMActivity;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\MetaIntegration\Events\CrmLeadCreated;
use App\Modules\MetaIntegration\Models\MetaConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class MetaWhatsAppService
{
    public function __construct(private readonly MetaHashingService $hashing) {}

    public function businessAccounts(MetaConnection $connection): array
    {
        if (! $connection->business_id) {
            throw ValidationException::withMessages([
                'business_id' => ['Select a Meta Business before choosing a WhatsApp Business account.'],
            ]);
        }

        return (new MetaGraphClient($connection))->get("{$connection->business_id}/owned_whatsapp_business_accounts", [
            'fields' => 'id,name',
        ])['data'] ?? [];
    }

    public function phoneNumbers(MetaConnection $connection, string $wabaId): array
    {
        return (new MetaGraphClient($connection))->get("{$wabaId}/phone_numbers", [
            'fields' => 'id,display_phone_number,verified_name,quality_rating',
        ])['data'] ?? [];
    }

    public function saveSettings(MetaConnection $connection, array $data): MetaConnection
    {
        $connection->update([
            'whatsapp_enabled' => (bool) ($data['whatsapp_enabled'] ?? $connection->whatsapp_enabled),
            'whatsapp_business_account_id' => $data['whatsapp_business_account_id'] ?? $connection->whatsapp_business_account_id,
            'whatsapp_phone_number_id' => $data['whatsapp_phone_number_id'] ?? $connection->whatsapp_phone_number_id,
            'whatsapp_phone_number' => $data['whatsapp_phone_number'] ?? $connection->whatsapp_phone_number,
        ]);

        return $connection->fresh();
    }

    public function sendTemplate(MetaConnection $connection, array $data): array
    {
        if (! $connection->whatsapp_enabled || ! $connection->whatsapp_phone_number_id) {
            throw ValidationException::withMessages([
                'whatsapp' => ['Enable WhatsApp and select a phone number before sending messages.'],
            ]);
        }

        $contact = null;
        if (! empty($data['contact_id'])) {
            $contact = CRMContact::where('company_id', $connection->company_id)->findOrFail($data['contact_id']);
        }

        $to = $this->hashing->normalizePhone($data['to_phone'] ?? $contact?->phone);
        if (! $to) {
            throw ValidationException::withMessages([
                'to_phone' => ['A destination phone number (or a contact with a phone) is required.'],
            ]);
        }

        $response = (new MetaGraphClient($connection))->postJson("{$connection->whatsapp_phone_number_id}/messages", [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $data['template_name'],
                'language' => ['code' => $data['language'] ?? 'en_US'],
                ...(empty($data['components']) ? [] : ['components' => $data['components']]),
            ],
        ]);

        if ($contact) {
            CRMActivity::create([
                'company_id' => $connection->company_id,
                'contact_id' => $contact->id,
                'user_id' => $data['user_id'] ?? null,
                'type' => 'whatsapp',
                'subject' => "WhatsApp template sent: {$data['template_name']}",
                'body' => $response['messages'][0]['id'] ?? null,
                'occurred_at' => now(),
            ]);
        }

        return $response;
    }

    public function handleInboundMessage(string $phoneNumberId, string $from, ?string $profileName, ?string $text, string $messageId): ?CRMActivity
    {
        $connection = MetaConnection::where('whatsapp_phone_number_id', $phoneNumberId)
            ->where('whatsapp_enabled', true)
            ->first();

        if (! $connection) {
            return null;
        }

        // Meta redelivers webhooks on slow/failed responses; skip already-seen messages.
        if (! Cache::add("meta.whatsapp.msg.{$messageId}", true, now()->addDay())) {
            return null;
        }

        $contact = $this->matchContactByPhone($connection->company_id, $from);

        if (! $contact) {
            $contact = CRMContact::create([
                'company_id' => $connection->company_id,
                'type' => 'lead',
                'status' => 'new',
                'name' => $profileName ?: $from,
                'phone' => $from,
                'source' => 'whatsapp',
                'currency' => 'EGP',
            ]);
            event(new CrmLeadCreated($contact));
        }

        return CRMActivity::create([
            'company_id' => $connection->company_id,
            'contact_id' => $contact->id,
            'type' => 'whatsapp',
            'subject' => 'WhatsApp message received',
            'body' => $text,
            'occurred_at' => now(),
        ]);
    }

    private function matchContactByPhone(int $companyId, string $phone): ?CRMContact
    {
        $digits = $this->hashing->normalizePhone($phone);
        if (! $digits) {
            return null;
        }

        // Stored phones vary in formatting (+20..., 0020..., spaces), so match on
        // the last digits rather than exact equality.
        $suffix = substr($digits, -9);

        return CRMContact::where('company_id', $companyId)
            ->whereNotNull('phone')
            ->where('phone', 'like', "%{$suffix}")
            ->first();
    }
}
