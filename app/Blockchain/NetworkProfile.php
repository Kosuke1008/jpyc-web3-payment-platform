<?php

namespace App\Blockchain;

final readonly class NetworkProfile
{
    public function __construct(
        public string $id,
        public int $version,
        public int $chainId,
        public string $chainName,
        public string $rpcUrl,
        public ?string $secondaryRpcUrl,
        public string $explorerUrl,
        public string $currencyName,
        public string $currencySymbol,
        public int $currencyDecimals,
        public string $jpycContract,
        public string $jpycSymbol,
        public int $jpycDecimals,
        public bool $testnet,
        public bool $paymentExecutionEnabled,
        public bool $feeDelegationExecutionEnabled,
    ) {}

    public function isKaiaMainnet(): bool
    {
        return $this->id === 'kaia-mainnet';
    }
}
