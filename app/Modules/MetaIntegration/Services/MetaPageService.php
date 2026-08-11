<?php

namespace App\Modules\MetaIntegration\Services;

use App\Modules\MetaIntegration\Exceptions\MetaGraphException;
use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\MetaIntegration\Models\MetaPage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the company's Facebook Pages — and the Instagram Business accounts
 * linked to them — in step with Meta, and owns their webhook subscriptions.
 *
 * Page access tokens are stored here (encrypted) because subscribing a Page to
 * webhooks and reading its leads both require a page-scoped token. They are
 * never exposed through the API.
 */
class MetaPageService
{
    public function __construct(private readonly MetaConnectionNotifier $notifier) {}

    /**
     * Pull the Page list from Meta and reconcile it with what we hold.
     *
     * @return Collection<int, MetaPage>
     */
    public function sync(MetaConnection $connection): Collection
    {
        try {
            $response = (new MetaGraphClient($connection))->get('me/accounts', [
                'fields' => 'id,name,category,access_token,tasks,instagram_business_account{id,username}',
                'limit' => 100,
            ]);
        } catch (MetaGraphException $exception) {
            $this->notifier->syncFailed($connection, 'page', $exception->getMessage());

            throw $exception;
        }

        $seen = [];

        foreach ($response['data'] ?? [] as $page) {
            if (empty($page['id'])) {
                continue;
            }

            $seen[] = $page['id'];

            MetaPage::updateOrCreate(
                ['meta_connection_id' => $connection->id, 'page_id' => $page['id']],
                [
                    'company_id' => $connection->company_id,
                    'name' => $page['name'] ?? null,
                    'category' => $page['category'] ?? null,
                    'access_token' => $page['access_token'] ?? null,
                    'tasks' => $page['tasks'] ?? null,
                    'instagram_account_id' => $page['instagram_business_account']['id'] ?? null,
                    'instagram_username' => $page['instagram_business_account']['username'] ?? null,
                    'is_active' => true,
                    'synced_at' => now(),
                ],
            );
        }

        // Pages the user no longer administers stay as history but go inactive.
        MetaPage::where('meta_connection_id', $connection->id)
            ->when($seen, fn ($query) => $query->whereNotIn('page_id', $seen))
            ->update(['is_active' => false]);

        Log::info('[meta] pages synced', [
            'company_id' => $connection->company_id,
            'pages' => count($seen),
        ]);

        return MetaPage::where('meta_connection_id', $connection->id)->active()->get();
    }

    /**
     * Subscribe the Page to the app's webhooks. Without this Meta never
     * delivers leadgen or message events, which is invisible until someone
     * notices no leads are arriving.
     */
    public function subscribe(MetaPage $page): bool
    {
        $token = $page->access_token;

        if (! $token) {
            $page->forceFill(['last_error' => 'No page access token; re-sync pages first.'])->save();

            return false;
        }

        $fields = (array) config('meta.webhook_page_fields', ['leadgen']);

        try {
            MetaGraphClient::withToken($token)->post("{$page->page_id}/subscribed_apps", [
                'subscribed_fields' => implode(',', $fields),
            ]);
        } catch (MetaGraphException $exception) {
            $page->forceFill(['last_error' => $exception->getMessage()])->save();
            $this->notifier->syncFailed($page->connection, 'webhook subscription', $exception->getMessage());

            return false;
        }

        $page->forceFill([
            'webhook_subscribed_at' => now(),
            'webhook_fields' => $fields,
            'last_error' => null,
        ])->save();

        return true;
    }

    public function unsubscribe(MetaPage $page): bool
    {
        if (! $page->access_token) {
            return false;
        }

        try {
            MetaGraphClient::withToken($page->access_token)->delete("{$page->page_id}/subscribed_apps");
        } catch (MetaGraphException $exception) {
            $page->forceFill(['last_error' => $exception->getMessage()])->save();

            return false;
        }

        $page->forceFill(['webhook_subscribed_at' => null, 'webhook_fields' => null])->save();

        return true;
    }

    /** Confirm Meta still lists the app as subscribed to this Page. */
    public function verifySubscription(MetaPage $page): bool
    {
        if (! $page->access_token) {
            return false;
        }

        try {
            $response = MetaGraphClient::withToken($page->access_token)->get("{$page->page_id}/subscribed_apps");
        } catch (MetaGraphException) {
            return false;
        }

        $appId = $page->connection->appId();
        $subscribed = collect($response['data'] ?? [])
            ->contains(fn (array $app) => (string) ($app['id'] ?? '') === (string) $appId);

        if (! $subscribed && $page->isSubscribed()) {
            // Drifted: Meta dropped the subscription behind our back.
            $page->forceFill(['webhook_subscribed_at' => null])->save();
        }

        return $subscribed;
    }

    /** Instagram Business accounts reachable through the connected Pages. */
    public function instagramAccounts(MetaConnection $connection): Collection
    {
        return MetaPage::where('meta_connection_id', $connection->id)
            ->active()
            ->whereNotNull('instagram_account_id')
            ->get()
            ->map(fn (MetaPage $page) => [
                'instagram_account_id' => $page->instagram_account_id,
                'username' => $page->instagram_username,
                'page_id' => $page->page_id,
                'page_name' => $page->name,
            ]);
    }

    /** Profile and follower metrics for a connected Instagram account. */
    public function instagramProfile(MetaConnection $connection, string $instagramAccountId): array
    {
        $page = MetaPage::where('meta_connection_id', $connection->id)
            ->where('instagram_account_id', $instagramAccountId)
            ->firstOrFail();

        return MetaGraphClient::withToken($page->access_token ?: $connection->access_token)
            ->get($instagramAccountId, [
                'fields' => 'id,username,name,profile_picture_url,followers_count,follows_count,media_count',
            ]);
    }
}
