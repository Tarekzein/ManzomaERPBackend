<?php

namespace App\Modules\MetaIntegration\Services;

use App\Modules\MetaIntegration\Models\MetaConnection;

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

    public function pages(MetaConnection $connection): array
    {
        return (new MetaGraphClient($connection))->get('me/accounts', [
            'fields' => 'id,name,access_token',
        ])['data'] ?? [];
    }

    public function leadForms(MetaConnection $connection, string $pageId): array
    {
        return (new MetaGraphClient($connection))->get("{$pageId}/leadgen_forms", [
            'fields' => 'id,name,status',
        ])['data'] ?? [];
    }

    public function selectAssets(MetaConnection $connection, array $data): MetaConnection
    {
        $connection->update(array_filter([
            'business_id' => $data['business_id'] ?? $connection->business_id,
            'ad_account_id' => $data['ad_account_id'] ?? $connection->ad_account_id,
            'pixel_id' => $data['pixel_id'] ?? $connection->pixel_id,
            'page_ids' => $data['page_ids'] ?? $connection->page_ids,
            'default_page_id' => $data['default_page_id'] ?? $connection->default_page_id,
        ], fn ($value) => $value !== null));

        return $connection->fresh();
    }
}
