<?php

namespace App\Modules\MetaIntegration\Services;

use App\Modules\MetaIntegration\Models\MetaConnection;
use Illuminate\Support\Arr;

class MetaAssetService
{
    public function businesses(MetaConnection $connection): array
    {
        return (new MetaGraphClient($connection))->get('me/businesses')['data'] ?? [];
    }

    public function adAccounts(MetaConnection $connection, string $businessId): array
    {
        return (new MetaGraphClient($connection))->get("{$businessId}/owned_ad_accounts", [
            'fields' => 'id,name,account_id,currency',
        ])['data'] ?? [];
    }

    public function pixels(MetaConnection $connection, string $adAccountId): array
    {
        return (new MetaGraphClient($connection))->get("{$adAccountId}/adspixels", [
            'fields' => 'id,name',
        ])['data'] ?? [];
    }

    /**
     * Pages the connected user administers.
     *
     * Page access tokens are deliberately not returned: they can post as the
     * Page and read its leads, so they never leave the server. Use
     * {@see pageAccessToken()} where one is needed.
     */
    public function pages(MetaConnection $connection): array
    {
        $pages = (new MetaGraphClient($connection))->get('me/accounts', [
            'fields' => 'id,name,instagram_business_account{id,username},tasks',
        ])['data'] ?? [];

        return array_map(
            fn (array $page) => Arr::except($page, ['access_token']),
            $pages,
        );
    }

    /**
     * Server-side only: a page-scoped token, needed to subscribe a Page to
     * webhooks and to read its lead data.
     */
    public function pageAccessToken(MetaConnection $connection, string $pageId): ?string
    {
        $page = (new MetaGraphClient($connection))->get($pageId, ['fields' => 'access_token']);

        return $page['access_token'] ?? null;
    }

    public function leadForms(MetaConnection $connection, string $pageId): array
    {
        return (new MetaGraphClient($connection))->get("{$pageId}/leadgen_forms", [
            'fields' => 'id,name,status',
        ])['data'] ?? [];
    }

    public function selectAssets(MetaConnection $connection, array $data): MetaConnection
    {
        // Only mutate keys the client sent. Explicit nulls and an empty page
        // array are meaningful: they let a company clear a stale selection.
        // Using `??` or array_filter here would silently restore the old value.
        $connection->update(Arr::only($data, [
            'business_id',
            'ad_account_id',
            'pixel_id',
            'page_ids',
            'default_page_id',
        ]));

        return $connection->fresh();
    }
}
