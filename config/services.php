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
        'mode' => env('PAYMOB_MODE', 'mock'),
        'api_key' => env('PAYMOB_API_KEY'),
        'integration_id' => env('PAYMOB_INTEGRATION_ID'),
        'iframe_id' => env('PAYMOB_IFRAME_ID'),
        'hmac_secret' => env('PAYMOB_HMAC_SECRET'),
        'base_url' => env('PAYMOB_BASE_URL', 'https://accept.paymob.com/api'),
        'currency' => env('PAYMOB_CURRENCY', 'EGP'),
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
