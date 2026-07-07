<?php

namespace App\Modules\MetaIntegration\Jobs;

use App\Modules\MetaIntegration\Services\MetaWhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessMetaWhatsAppWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
}
