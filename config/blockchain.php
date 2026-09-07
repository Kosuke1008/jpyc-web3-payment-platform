<?php

return [
    // BLOCKCHAIN_NETWORK is intentionally independent from APP_ENV. The
    // WEB3_NETWORK fallback preserves existing deployments during migration.
    'network' => env('BLOCKCHAIN_NETWORK', env('WEB3_NETWORK')),

    // Phase 1 defines Mainnet metadata for validation/readiness only. Payment
    // execution remains disabled in the profile even if this flag is true.
    'payments_mainnet_enabled' => env('PAYMENTS_MAINNET_ENABLED', false),
    'mainnet_fee_delegation_enabled' => env('MAINNET_FEE_DELEGATION_ENABLED', false),
    'mainnet_broadcast_enabled' => env('MAINNET_BROADCAST_ENABLED', false),
    'mainnet_readiness_max_block_lag' => env('MAINNET_READINESS_MAX_BLOCK_LAG', 10),

    'payment_max_jpy' => env('PAYMENT_MAX_JPY', 100000),
    'payment_expiration_seconds' => env('PAYMENT_EXPIRATION_SECONDS', 600),

    'profiles' => [
        'kairos' => [
            'version' => 1,
            'chain_id' => 1001,
            'chain_name' => 'Kaia Kairos Testnet',
            'rpc_url' => env(
                'BLOCKCHAIN_KAIROS_RPC_URL',
                env('KAIROS_RPC_URL')
            ),
            'explorer_url' => 'https://kairos.kaiascan.io',
            'native_currency' => [
                'name' => 'KAIA',
                'symbol' => 'KAIA',
                'decimals' => 18,
            ],
            'jpyc' => [
                'contract' => '0xe7c3d8c9a439fede00d2600032d5db0be71c3c29',
                'symbol' => 'JPYC',
                'decimals' => 18,
            ],
            'testnet' => true,
            'payment_execution_enabled' => true,
            'fee_delegation_execution_enabled' => true,
        ],

        'kaia-mainnet' => [
            'version' => 1,
            'chain_id' => 8217,
            'chain_name' => 'Kaia Mainnet',
            'rpc_url' => env('BLOCKCHAIN_KAIA_MAINNET_RPC_URL'),
            'secondary_rpc_url' => env('BLOCKCHAIN_KAIA_MAINNET_SECONDARY_RPC_URL'),
            'explorer_url' => 'https://kaiascan.io',
            'native_currency' => [
                'name' => 'KAIA',
                'symbol' => 'KAIA',
                'decimals' => 18,
            ],
            'jpyc' => [
                'contract' => '0xe7c3d8c9a439fede00d2600032d5db0be71c3c29',
                'symbol' => 'JPYC',
                'decimals' => 18,
            ],
            'testnet' => false,
            // Deliberately hard-disabled until later migration phases.
            'payment_execution_enabled' => false,
            'fee_delegation_execution_enabled' => false,
        ],

        // Explicitly isolated compatibility profile for the pre-Kaia flow.
        // It is not a Kaia network alias and is never selected implicitly.
        'sepolia' => [
            'version' => 1,
            'chain_id' => 11155111,
            'chain_name' => 'Ethereum Sepolia',
            'rpc_url' => env('ALCHEMY_RPC_URL'),
            'explorer_url' => 'https://sepolia.etherscan.io',
            'native_currency' => [
                'name' => 'Sepolia ETH',
                'symbol' => 'ETH',
                'decimals' => 18,
            ],
            'jpyc' => [
                'contract' => env('ERC20_CONTRACT_ADDRESS'),
                'symbol' => env('WEB3_TOKEN_SYMBOL', 'JPYC'),
                'decimals' => env('WEB3_TOKEN_DECIMALS', 18),
            ],
            'testnet' => true,
            'payment_execution_enabled' => true,
            'fee_delegation_execution_enabled' => false,
        ],
    ],
];
