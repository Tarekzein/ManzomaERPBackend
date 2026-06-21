<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Notifications\Channels\TwilioSmsChannel;
use App\Modules\Notifications\Models\NotificationDeliveryLog;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Notifications\EventNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class NotificationService
{
    public function eventTypes(): array
    {
        return [
            'hr.leave.requested' => ['name' => 'Leave request submitted', 'category' => 'Approvals', 'critical' => false],
            'hr.leave.reviewed' => ['name' => 'Leave request reviewed', 'category' => 'Approvals', 'critical' => false],
            'inventory.reorder' => ['name' => 'Inventory reorder point reached', 'category' => 'Alerts', 'critical' => true],
            'projects.task.due' => ['name' => 'Project task due date', 'category' => 'Due dates', 'critical' => false],
            'crm.followup.due' => ['name' => 'CRM follow-up due date', 'category' => 'Due dates', 'critical' => false],
            'finance.invoice.overdue' => ['name' => 'Finance invoice overdue', 'category' => 'Alerts', 'critical' => true],
            'system.announcement' => ['name' => 'System announcement', 'category' => 'System', 'critical' => false],
        ];
    }

    public function preferences(User $user): array
    {
        $saved = NotificationPreference::where('user_id', $user->id)->get()->keyBy('event_type');

        return collect($this->eventTypes())->map(function (array $event, string $type) use ($saved) {
            $preference = $saved->get($type);

            return ['event_type' => $type] + $event + [
                'in_app' => $preference?->in_app ?? true,
                'email' => $preference?->email ?? true,
                'sms' => $preference?->sms ?? false,
            ];
        })->values()->all();
    }

    public function savePreferences(User $user, array $preferences): array
    {
        foreach ($preferences as $preference) {
            abort_unless(isset($this->eventTypes()[$preference['event_type']]), 422, 'Unknown notification event type.');
            NotificationPreference::updateOrCreate(
                ['user_id' => $user->id, 'event_type' => $preference['event_type']],
                collect($preference)->only(['in_app', 'email', 'sms'])->all()
            );
        }

        return $this->preferences($user);
    }

    public function send(User|Collection|array $recipients, string $eventType, string $title, string $message, array $payload = [], ?string $actionUrl = null, string $severity = 'info'): void
    {
        $users = $recipients instanceof User ? collect([$recipients]) : collect($recipients);
        foreach ($users as $user) {
            $this->configureCompanyMail($user);
            $channels = $this->channels($user, $eventType);
            if (! $channels) {
                continue;
            }
            try {
                Notification::send($user, new EventNotification($eventType, $title, $message, $payload, $actionUrl, $severity, $channels));
                foreach (array_filter($channels, fn ($channel) => $channel !== TwilioSmsChannel::class) as $channel) {
                    NotificationDeliveryLog::create([
                        'company_id' => $user->company_id, 'user_id' => $user->id, 'event_type' => $eventType,
                        'channel' => $channel === 'database' ? 'in_app' : $channel, 'status' => 'sent',
                        'provider' => $channel === 'mail' ? config('mail.default') : 'database', 'destination' => $channel === 'mail' ? $user->email : null,
                    ]);
                }
            } catch (\Throwable $exception) {
                NotificationDeliveryLog::create([
                    'company_id' => $user->company_id, 'user_id' => $user->id, 'event_type' => $eventType,
                    'channel' => 'delivery', 'status' => 'failed', 'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function channels(User $user, string $eventType): array
    {
        $preference = NotificationPreference::where('user_id', $user->id)->where('event_type', $eventType)->first();
        $settings = $user->company?->settings['notifications'] ?? [];
        $channels = [];
        if ($preference?->in_app ?? true) {
            $channels[] = 'database';
        }
        if (($settings['email']['enabled'] ?? true) && ($preference?->email ?? true) && $user->email) {
            $channels[] = 'mail';
        }
        if (($settings['sms']['enabled'] ?? false) && ($preference?->sms ?? false) && $user->routeNotificationForSms()) {
            $channels[] = TwilioSmsChannel::class;
        }

        return $channels;
    }

    private function configureCompanyMail(User $user): void
    {
        $email = $user->company?->settings['notifications']['email'] ?? [];
        $mailer = $email['mailer'] ?? config('mail.default');
        config(['mail.default' => $mailer]);
        if ($mailer === 'smtp') {
            foreach (['host', 'port', 'username', 'password', 'encryption'] as $key) {
                if (! empty($email[$key])) {
                    config(["mail.mailers.smtp.{$key}" => $key === 'password' ? NotificationSecrets::decrypt($email[$key]) : $email[$key]]);
                }
            }
        }
        if (! empty($email['from_address'])) {
            config(['mail.from.address' => $email['from_address']]);
        }
        if (! empty($email['from_name'])) {
            config(['mail.from.name' => $email['from_name']]);
        }
    }
}
