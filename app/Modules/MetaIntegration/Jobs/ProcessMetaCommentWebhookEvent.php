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

/**
 * A comment on a connected Page lands in the social inbox. Ownership is
 * resolved from the page id, so an unmapped page is ignored rather than
 * attributed to the wrong tenant.
 */
class ProcessMetaCommentWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [30, 120, 600, 1800];

    public int $timeout = 60;

    public function __construct(
        private readonly string $pageId,
        private readonly array $value,
        private readonly string $platform = 'facebook',
    ) {}

    public function handle(SocialInboxService $inbox): void
    {
        $page = MetaPage::query()
            ->when(
                $this->platform === 'instagram',
                fn ($query) => $query->where('instagram_account_id', $this->pageId),
                fn ($query) => $query->where('page_id', $this->pageId),
            )
            ->active()
            ->first();

        if (! $page) {
            return;
        }

        $inbox->record($page->company_id, [
            'platform' => $this->platform,
            'type' => 'comment',
            'external_id' => (string) ($this->value['comment_id'] ?? $this->value['id'] ?? ''),
            'parent_external_id' => $this->value['post_id'] ?? $this->value['media']['id'] ?? null,
            'page_id' => $this->platform === 'instagram' ? $page->instagram_account_id : $page->page_id,
            'author_external_id' => $this->value['from']['id'] ?? null,
            'author_name' => $this->value['from']['name'] ?? $this->value['from']['username'] ?? null,
            'message' => $this->value['message'] ?? $this->value['text'] ?? null,
            'permalink' => $this->value['permalink_url'] ?? null,
            'posted_at' => isset($this->value['created_time'])
                ? now()->setTimestamp((int) $this->value['created_time'])
                : now(),
            'payload' => $this->value,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[meta] comment webhook job failed', [
            'page_id' => $this->pageId,
            'comment_id' => $this->value['comment_id'] ?? $this->value['id'] ?? null,
            'message' => $exception->getMessage(),
        ]);
    }
}
