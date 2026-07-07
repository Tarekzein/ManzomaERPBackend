<?php

namespace App\Modules\MetaIntegration\Jobs;

use App\Modules\MetaIntegration\Services\MetaLeadAdsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessMetaLeadWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $pageId,
        private readonly string $formId,
        private readonly string $leadgenId,
    ) {}

    public function handle(MetaLeadAdsService $leadAds): void
    {
        $leadAds->ingest($this->pageId, $this->formId, $this->leadgenId);
    }
}
