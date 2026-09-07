<?php

return [

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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    'livt_wallet' => [
        'url' => env('LIVT_WALLET_URL'),
        'payment_token_expiration_minutes' => env(
            'LIVT_PAYMENT_TOKEN_EXPIRATION_MINUTES',
            30
        ),
    ],

    'fee_delegation' => [
        'enabled' => env('KAIA_FEE_DELEGATION_ENABLED', false),
        // Explicit provider selection; never derive this from APP_ENV.
        'mode' => env('FEE_DELEGATION_MODE', 'self-hosted'),
        'url' => env('KAIA_FEE_DELEGATION_URL'),
        'api_key' => env('KAIA_FEE_DELEGATION_API_KEY'),
        'max_gas' => env('KAIA_FEE_DELEGATION_MAX_GAS', 150000),
        'timeout_seconds' => env(
            'KAIA_FEE_DELEGATION_TIMEOUT_SECONDS',
            60
        ),
        // Phase 7: a deliberately selected, single-Payment Kairos test only.
        'kairos_managed_live_test_enabled' => env(
            'KAIROS_MANAGED_LIVE_TEST_ENABLED',
            false
        ),
        'kairos_managed_live_test_payment_id' => env(
            'KAIROS_MANAGED_LIVE_TEST_PAYMENT_ID'
        ),
        'kairos_managed_live_test_max_jpy' => env(
            'KAIROS_MANAGED_LIVE_TEST_MAX_JPYC'
        ),
    ],

];
