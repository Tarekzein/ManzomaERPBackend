<?php

namespace App\Modules\Platform\Services;

use App\Modules\MetaIntegration\Exceptions\MetaGraphException;
use App\Modules\MetaIntegration\Models\MetaPage;
use App\Modules\MetaIntegration\Services\MetaGraphClient;
use Illuminate\Support\Facades\Log;

/**
 * Publishing to connected accounts.
 *
 * Facebook Pages accept a single call. Instagram does not: you create a media
 * container, then publish it, and the container needs a publicly reachable
 * image URL because Instagram fetches it itself.
 *
 * TikTok is absent on purpose — its Content Posting API is a separate product
 * from the Marketing API this system is approved for.
 */
class SocialPublishingService
{
    /** @return array{id: string, platform: string, permalink: ?string} */
    public function publishToPage(MetaPage $page, string $message, ?string $link = null): array
    {
        $token = $this->pageToken($page);

        $response = MetaGraphClient::withToken($token)->post("{$page->page_id}/feed", array_filter([
            'message' => $message,
            'link' => $link,
        ]));

        $id = (string) ($response['id'] ?? '');

        Log::info('[social] page post published', [
            'company_id' => $page->company_id,
            'page_id' => $page->page_id,
            'post_id' => $id,
        ]);

        return [
            'id' => $id,
            'platform' => 'facebook',
            'permalink' => $id ? "https://www.facebook.com/{$id}" : null,
        ];
    }

    /**
     * Instagram publishing is two calls: build a container, then publish it.
     * The image must be reachable by Instagram's own fetchers, so a local or
     * signed-private URL will fail at the container step.
     */
    public function publishToInstagram(MetaPage $page, string $imageUrl, ?string $caption = null): array
    {
        abort_unless($page->instagram_account_id, 422, 'This page has no linked Instagram Business account.');

        $token = $this->pageToken($page);
        $client = MetaGraphClient::withToken($token);

        $container = $client->post("{$page->instagram_account_id}/media", array_filter([
            'image_url' => $imageUrl,
            'caption' => $caption,
        ]));

        $creationId = $container['id'] ?? null;

        if (! $creationId) {
            throw new MetaGraphException('Instagram did not return a media container id.');
        }

        $this->waitForInstagramContainer($client, (string) $creationId);

        $published = $client->post("{$page->instagram_account_id}/media_publish", [
            'creation_id' => $creationId,
        ]);

        $id = (string) ($published['id'] ?? '');

        Log::info('[social] instagram post published', [
            'company_id' => $page->company_id,
            'instagram_account_id' => $page->instagram_account_id,
            'media_id' => $id,
        ]);

        return [
            'id' => $id,
            'platform' => 'instagram',
            'permalink' => null, // Fetch via /{media-id}?fields=permalink when needed.
        ];
    }

    /**
     * Meta prepares Instagram media asynchronously. Publishing before the
     * container reaches FINISHED produces intermittent "media not ready"
     * failures, so poll for a bounded period before making the publish call.
     */
    private function waitForInstagramContainer(MetaGraphClient $client, string $creationId): void
    {
        $attempts = max((int) config('meta.instagram_container_poll_attempts', 10), 1);
        $delayMilliseconds = max((int) config('meta.instagram_container_poll_delay_ms', 500), 0);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $container = $client->get($creationId, [
                'fields' => 'status_code,status',
            ]);
            $statusCode = strtoupper(trim((string) ($container['status_code'] ?? '')));

            if ($statusCode === 'FINISHED') {
                return;
            }

            if (in_array($statusCode, ['ERROR', 'EXPIRED'], true)) {
                $details = trim((string) ($container['status'] ?? ''));
                $message = 'Instagram could not prepare the media container.';

                throw new MetaGraphException($details === '' ? $message : "{$message} {$details}");
            }

            if ($attempt < $attempts && $delayMilliseconds > 0) {
                usleep($delayMilliseconds * 1_000);
            }
        }

        throw new MetaGraphException('Instagram is still processing the media. Try publishing again shortly.');
    }

    private function pageToken(MetaPage $page): string
    {
        abort_unless($page->is_active, 422, 'This page is no longer connected.');
        abort_unless($page->access_token, 422, 'Re-sync your pages to refresh the page token.');

        return $page->access_token;
    }
}
