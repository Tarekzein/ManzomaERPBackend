<?php

return [
    /*
    |--------------------------------------------------------------------------
    | No platform-wide app
    |--------------------------------------------------------------------------
    |
    | Every company connects their own Meta App from Company profile → Meta
    | Integration. App id, secret and webhook verify token live on the tenant's
    | `meta_connections` row, so there is deliberately no META_APP_ID /
    | META_APP_SECRET here to fall back to.
    |
    */

    'graph_version' => env('META_GRAPH_VERSION', 'v21.0'),
    'graph_base_url' => env('META_GRAPH_BASE_URL', 'https://graph.facebook.com'),
    'oauth_dialog_url' => env('META_OAUTH_DIALOG_URL', 'https://www.facebook.com'),
    'redirect_uri' => env('META_REDIRECT_URI'),

    'scopes' => [
        'ads_management',
        'ads_read',
        'business_management',
        'leads_retrieval',
        'pages_show_list',
        'pages_manage_metadata',
        'pages_manage_posts',
        'pages_manage_engagement',
        'pages_messaging',
        'pages_read_engagement',
        'pages_read_user_content',
        'instagram_basic',
        'instagram_content_publish',
        'instagram_manage_comments',
        'instagram_manage_messages',
        'whatsapp_business_management',
        'whatsapp_business_messaging',
    ],

    'request_retries' => (int) env('META_REQUEST_RETRIES', 3),
    'usage_warning_percent' => (int) env('META_USAGE_WARNING_PERCENT', 80),
    'token_refresh_lead_days' => (int) env('META_TOKEN_REFRESH_LEAD_DAYS', 7),
    'instagram_container_poll_attempts' => (int) env('META_INSTAGRAM_CONTAINER_POLL_ATTEMPTS', 10),
    'instagram_container_poll_delay_ms' => (int) env('META_INSTAGRAM_CONTAINER_POLL_DELAY_MS', 500),

    /**
     * Scopes a connection must actually hold. Checked against what Meta granted
     * (not what we requested), so a declined permission surfaces immediately.
     */
    'required_scopes' => [
        'ads_management',
        'leads_retrieval',
        'pages_show_list',
        'pages_manage_metadata',
        'pages_manage_posts',
        'pages_manage_engagement',
        'pages_messaging',
        'pages_read_engagement',
        'pages_read_user_content',
        'instagram_basic',
        'instagram_content_publish',
        'instagram_manage_comments',
        'instagram_manage_messages',
        'whatsapp_business_messaging',
    ],

    'webhook_page_fields' => ['leadgen', 'messages', 'feed'],

    'capi_batch_size' => (int) env('META_CAPI_BATCH_SIZE', 1000),
    'audience_batch_size' => (int) env('META_AUDIENCE_BATCH_SIZE', 1000),
    'max_retry_attempts' => (int) env('META_MAX_RETRY_ATTEMPTS', 5),
    'retry_backoff_seconds' => [30, 120, 600, 1800, 3600],
];
