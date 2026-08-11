<?php

namespace App\Modules\MetaIntegration\Jobs;

use App\Modules\MetaIntegration\Models\MetaPage;
use App\Modules\Platform\Services\SocialInboxService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Records Facebook Page and Instagram direct messages in the support inbox. */
class ProcessMetaMessageWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [30, 120, 600, 1800];

    public int $timeout = 60;

    public function __construct(
        private readonly string $accountId,
        private readonly array $event,
    ) {}

    public function handle(SocialInboxService $inbox): void
    {
        $page = MetaPage::query()
            ->where(function ($query) {
                $query->where('page_id', $this->accountId)
                    ->orWhere('instagram_account_id', $this->accountId);
            })
            ->active()
            ->first();

        if (! $page) {
            return;
        }

        $platform = $page->instagram_account_id === $this->accountId ? 'instagram' : 'facebook';
        $message = $this->event['message'] ?? [];

        $inbox->record((int) $page->company_id, [
            'platform' => $platform,
            'type' => 'message',
            'external_id' => (string) ($message['mid'] ?? ''),
            'page_id' => $this->accountId,
            'author_external_id' => $this->event['sender']['id'] ?? null,
            'author_name' => $this->event['sender']['name'] ?? null,
            'message' => $message['text'] ?? null,
            'posted_at' => isset($this->event['timestamp'])
                ? now()->setTimestamp((int) floor(((int) $this->event['timestamp']) / 1000))
                : now(),
            'payload' => $this->event,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[meta] message webhook job failed', [
            'account_id' => $this->accountId,
            'message_id' => $this->event['message']['mid'] ?? null,
            'message' => $exception->getMessage(),
        ]);
    }
}
