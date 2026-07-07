<?php

return [
    'app_id' => env('META_APP_ID'),
    'app_secret' => env('META_APP_SECRET'),
    'graph_version' => env('META_GRAPH_VERSION', 'v21.0'),
    'graph_base_url' => env('META_GRAPH_BASE_URL', 'https://graph.facebook.com'),
    'oauth_dialog_url' => env('META_OAUTH_DIALOG_URL', 'https://www.facebook.com'),
    'redirect_uri' => env('META_REDIRECT_URI'),
    'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),

    'scopes' => [
        'ads_management',
        'ads_read',
        'business_management',
        'leads_retrieval',
        'pages_show_list',
        'pages_manage_metadata',
        'pages_read_engagement',
        'whatsapp_business_management',
        'whatsapp_business_messaging',
    ],

    'capi_batch_size' => (int) env('META_CAPI_BATCH_SIZE', 1000),
    'audience_batch_size' => (int) env('META_AUDIENCE_BATCH_SIZE', 1000),
    'max_retry_attempts' => (int) env('META_MAX_RETRY_ATTEMPTS', 5),
    'retry_backoff_seconds' => [30, 120, 600, 1800, 3600],
];
