<?php

namespace App\Modules\Notifications\Channels;

use App\Modules\Notifications\Models\NotificationDeliveryLog;
use App\Modules\Notifications\Services\NotificationSecrets;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TwilioSmsChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $message = $notification->toSms($notifiable);
        $settings = $notifiable->company?->settings['notifications']['twilio'] ?? [];
        $to = $notifiable->routeNotificationForSms();
        $sid = $settings['sid'] ?? config('services.twilio.sid');
        $token = NotificationSecrets::decrypt($settings['token'] ?? null) ?? config('services.twilio.token');
        $from = $settings['from'] ?? config('services.twilio.from');

        if (! $to || ! $sid || ! $token || ! $from) {
            throw new RuntimeException('Twilio SMS settings or recipient phone number are missing.');
        }

        $response = Http::asForm()->withBasicAuth($sid, $token)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", ['To' => $to, 'From' => $from, 'Body' => $message]);

        if (! $response->successful()) {
            throw new RuntimeException('Twilio rejected the SMS notification.');
        }

        NotificationDeliveryLog::create([
            'company_id' => $notifiable->company_id, 'user_id' => $notifiable->id,
            'event_type' => $notification->eventType, 'channel' => 'sms', 'status' => 'sent',
            'provider' => 'twilio', 'destination' => $to, 'metadata' => ['sid' => $response->json('sid')],
        ]);
    }
}
