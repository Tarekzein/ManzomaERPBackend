<?php

return [
    /*
    |--------------------------------------------------------------------------
    | TikTok Marketing API
    |--------------------------------------------------------------------------
    |
    | As with Meta, every company connects their own TikTok for Business app
    | from their company profile. App id and secret live on the tenant's
    | `tiktok_connections` row, so there is deliberately no TIKTOK_APP_ID /
    | TIKTOK_APP_SECRET here to fall back to.
    |
    */

    'redirect_uri' => env('TIKTOK_REDIRECT_URI'),

    'api_version' => env('TIKTOK_API_VERSION', 'v1.3'),
    'base_url' => env('TIKTOK_BASE_URL', 'https://business-api.tiktok.com/open_api'),
    'auth_url' => env('TIKTOK_AUTH_URL', 'https://ads.tiktok.com/marketing_api/auth'),

    'webhook_secret' => env('TIKTOK_WEBHOOK_SECRET'),

    /**
     * Marketing API scopes. TikTok grants these per app after review; the
     * connection reports which were actually returned in `scope`.
     */
    'scopes' => [
        'user.info.basic',
        'ad.group.list',
        'campaign.list',
        'advertiser.list',
        'audience.management',
        'lead.management',
        'event.management',
    ],

    'required_scopes' => [
        'advertiser.list',
        'campaign.list',
    ],

    'request_retries' => (int) env('TIKTOK_REQUEST_RETRIES', 3),
    'token_refresh_lead_days' => (int) env('TIKTOK_TOKEN_REFRESH_LEAD_DAYS', 7),
    'audience_batch_size' => (int) env('TIKTOK_AUDIENCE_BATCH_SIZE', 1000),
    'max_retry_attempts' => (int) env('TIKTOK_MAX_RETRY_ATTEMPTS', 5),
    'retry_backoff_seconds' => [30, 120, 600, 1800, 3600],
];
