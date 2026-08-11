<?php

namespace App\Modules\MetaIntegration\Jobs;

use App\Modules\MetaIntegration\Services\MetaLeadAdsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMetaLeadWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Meta retries webhooks for ~36h; give the job a comparable window. */
    public int $tries = 5;

    public array $backoff = [30, 120, 600, 1800];

    public int $timeout = 60;

    public function __construct(
        private readonly string $pageId,
        private readonly string $formId,
        private readonly string $leadgenId,
    ) {}

    public function handle(MetaLeadAdsService $leadAds): void
    {
        $leadAds->ingest($this->pageId, $this->formId, $this->leadgenId);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[meta] lead webhook job failed', [
            'job' => static::class,
            'payload' => $this->logContext(),
            'message' => $exception->getMessage(),
        ]);
    }

    /** Identifiers only: lead field data is personal information. */
    private function logContext(): array
    {
        return [
            'page_id' => $this->pageId,
            'form_id' => $this->formId,
            'leadgen_id' => $this->leadgenId,
        ];
    }
}
