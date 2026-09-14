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
        'self_hosted_mainnet' => [
            'enabled' => env(
                'SELF_HOSTED_MAINNET_FEE_PAYER_ENABLED',
                false
            ),
            'kill_switch' => env('FEE_PAYER_KILL_SWITCH', true),
            'max_payment_jpy' => env(
                'SELF_HOSTED_MAINNET_MAX_PAYMENT_JPYC'
            ),
            'max_gas' => env('SELF_HOSTED_MAINNET_MAX_GAS'),
            'max_gas_price_wei' => env(
                'SELF_HOSTED_MAINNET_MAX_GAS_PRICE_WEI'
            ),
            'rate_window_seconds' => env(
                'SELF_HOSTED_MAINNET_RATE_WINDOW_SECONDS'
            ),
            'max_attempts_per_user' => env(
                'SELF_HOSTED_MAINNET_MAX_ATTEMPTS_PER_USER'
            ),
            'max_attempts_per_store' => env(
                'SELF_HOSTED_MAINNET_MAX_ATTEMPTS_PER_STORE'
            ),
            'max_attempts_per_sender' => env(
                'SELF_HOSTED_MAINNET_MAX_ATTEMPTS_PER_SENDER'
            ),
            'max_attempts_global' => env(
                'SELF_HOSTED_MAINNET_MAX_ATTEMPTS_GLOBAL'
            ),
            'daily_transaction_limit' => env(
                'SELF_HOSTED_MAINNET_DAILY_TRANSACTION_LIMIT'
            ),
            'daily_kaia_budget' => env(
                'SELF_HOSTED_MAINNET_DAILY_KAIA_BUDGET'
            ),
            'minimum_reserve_kaia' => env(
                'FEE_PAYER_MIN_RESERVE_KAIA'
            ),
        ],
        'mainnet_staging' => [
            'pilot_payment_id' => env('MAINNET_PILOT_PAYMENT_ID'),
            'pilot_max_fee_payer_balance_kaia' => env(
                'MAINNET_PILOT_MAX_FEE_PAYER_BALANCE_KAIA'
            ),
            'environment_id' => env('MAINNET_STAGING_ENVIRONMENT_ID'),
            'database_identifier' => env(
                'MAINNET_STAGING_DATABASE_IDENTIFIER'
            ),
            'kairos_database_identifier' => env(
                'KAIROS_DATABASE_IDENTIFIER'
            ),
            'cache_prefix' => env('MAINNET_STAGING_CACHE_PREFIX'),
            'kairos_cache_prefix' => env('KAIROS_CACHE_PREFIX'),
            'merchant_address' => env(
                'MAINNET_STAGING_MERCHANT_ADDRESS'
            ),
            'kairos_merchant_address' => env(
                'KAIROS_MERCHANT_ADDRESS'
            ),
            'fee_payer_address' => env(
                'FEE_PAYER_KAIA_MAINNET_ADDRESS'
            ),
            'kairos_fee_payer_address' => env(
                'FEE_PAYER_KAIROS_ADDRESS'
            ),
            'approved_user_ids' => env(
                'MAINNET_STAGING_APPROVED_USER_IDS'
            ),
            'approved_sender_addresses' => env(
                'MAINNET_STAGING_APPROVED_SENDER_ADDRESSES'
            ),
            'fee_payer_health_url' => env(
                'MAINNET_STAGING_FEE_PAYER_HEALTH_URL',
                'http://127.0.0.1:19000/health'
            ),
            'stage2_legacy_policy_resolved' => env(
                'MAINNET_STAGING_STAGE2_LEGACY_POLICY_RESOLVED',
                false
            ),
            'allow_cross_environment_reuse' => env(
                'MAINNET_STAGING_ALLOW_CROSS_ENVIRONMENT_REUSE',
                false
            ),
        ],
    ],

];
