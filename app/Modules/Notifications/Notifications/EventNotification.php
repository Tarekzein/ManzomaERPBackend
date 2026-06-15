<?php

namespace App\Modules\Notifications\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $eventType,
        private readonly string $title,
        private readonly string $message,
        private readonly array $payload = [],
        private readonly ?string $actionUrl = null,
        private readonly string $severity = 'info',
        private readonly array $channels = ['database'],
    ) {}

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'event_type' => $this->eventType, 'title' => $this->title, 'message' => $this->message,
            'severity' => $this->severity, 'action_url' => $this->actionUrl, 'payload' => $this->payload,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title)->line($this->message);
        if ($this->actionUrl) {
            $mail->action('Open in ManzomaERP', rtrim(config('app.url'), '/').$this->actionUrl);
        }

        return $mail;
    }

    public function toSms(object $notifiable): string
    {
        return "{$this->title}: {$this->message}";
    }
}
