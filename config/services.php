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

    'web3' => [
        'network' => env('WEB3_NETWORK', 'sepolia'),

        'rpc_url' => env('WEB3_NETWORK', 'sepolia') === 'kairos'
            ? env('KAIROS_RPC_URL')
            : env('ALCHEMY_RPC_URL'),

        'erc20_contract_address' => env('WEB3_NETWORK', 'sepolia') === 'kairos'
            ? env('KAIROS_ERC20_CONTRACT_ADDRESS')
            : env('ERC20_CONTRACT_ADDRESS'),

        'chain_id' => env('WEB3_NETWORK', 'sepolia') === 'kairos'
            ? 1001
            : 11155111,

        'chain_name' => env('WEB3_NETWORK', 'sepolia') === 'kairos'
            ? 'Kaia Kairos Testnet'
            : 'Ethereum Sepolia',

        'currency_name' => env('WEB3_NETWORK', 'sepolia') === 'kairos'
            ? 'KAIA'
            : 'Sepolia ETH',

        'currency_symbol' => env('WEB3_NETWORK', 'sepolia') === 'kairos'
            ? 'KAIA'
            : 'ETH',

        'block_explorer_url' => env('WEB3_NETWORK', 'sepolia') === 'kairos'
            ? 'https://kairos.kaiascan.io'
            : 'https://sepolia.etherscan.io',
    ],

];
