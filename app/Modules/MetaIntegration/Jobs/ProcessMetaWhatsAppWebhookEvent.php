<?php

namespace App\Modules\MetaIntegration\Jobs;

use App\Modules\MetaIntegration\Services\MetaWhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMetaWhatsAppWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Meta retries webhooks for ~36h; give the job a comparable window. */
    public int $tries = 5;

    public array $backoff = [30, 120, 600, 1800];

    public int $timeout = 60;

    public function __construct(
        private readonly string $phoneNumberId,
        private readonly string $from,
        private readonly ?string $profileName,
        private readonly ?string $text,
        private readonly string $messageId,
    ) {}

    public function handle(MetaWhatsAppService $whatsapp): void
    {
        $whatsapp->handleInboundMessage(
            $this->phoneNumberId,
            $this->from,
            $this->profileName,
            $this->text,
            $this->messageId,
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[meta] WhatsApp message webhook job failed', [
            'job' => static::class,
            'payload' => $this->logContext(),
            'message' => $exception->getMessage(),
        ]);
    }

    /** Identifiers only: the message body and sender number are personal information. */
    private function logContext(): array
    {
        return [
            'phone_number_id' => $this->phoneNumberId,
            'message_id' => $this->messageId,
        ];
    }
}
