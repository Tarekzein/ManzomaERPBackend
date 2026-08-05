<?php

return [
    'open_exchange_rates' => [
        'app_id' => env('OPEN_EXCHANGE_RATES_APP_ID'),
    ],
    'twilio' => [
        'sid' => env('TWILIO_ACCOUNT_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM'),
    ],
    'translation' => [
        'driver' => env('TRANSLATION_DRIVER', 'libretranslate'),
        'libretranslate' => [
            'url' => env('LIBRETRANSLATE_URL', 'http://127.0.0.1:5000'),
            'api_key' => env('LIBRETRANSLATE_API_KEY'),
            'timeout' => env('TRANSLATION_TIMEOUT', 10),
        ],
    ],
    'paymob' => [
        // "mock" keeps the in-app fake checkout; anything else talks to Paymob.
        'mode' => env('PAYMOB_MODE', 'mock'),
        // Unified Checkout (intention API) credentials.
        'public_key' => env('PAYMOB_PUBLIC_KEY'),
        'secret_key' => env('PAYMOB_SECRET_KEY'),
        // Legacy Accept credentials, also required for saved-card (MOTO) charges.
        'api_key' => env('PAYMOB_API_KEY'),
        'integration_id' => env('PAYMOB_INTEGRATION_ID'),
        'moto_integration_id' => env('PAYMOB_MOTO_INTEGRATION_ID'),
        'iframe_id' => env('PAYMOB_IFRAME_ID'),
        'hmac_secret' => env('PAYMOB_HMAC_SECRET'),
        'base_url' => env('PAYMOB_BASE_URL', 'https://accept.paymob.com/api'),
        'intention_url' => env('PAYMOB_INTENTION_URL', 'https://accept.paymob.com/v1/intention/'),
        'checkout_url' => env('PAYMOB_CHECKOUT_URL', 'https://accept.paymob.com/unifiedcheckout/'),
        'currency' => env('PAYMOB_CURRENCY', 'EGP'),
        'timeout' => (int) env('PAYMOB_TIMEOUT', 30),
    ],
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
