<?php

namespace App\Blockchain;

final class NetworkProfileRegistry
{
    /** @param array<string, mixed>|null $configuration */
    public function __construct(private readonly ?array $configuration = null) {}

    public function active(): NetworkProfile
    {
        $configuration = $this->configuration();
        $network = $configuration['network'] ?? null;

        if (! is_string($network)
            || preg_match('/\A[a-z][a-z0-9-]{1,31}\z/', $network) !== 1) {
            throw new NetworkConfigurationException(
                'BLOCKCHAIN_NETWORK is missing or malformed.'
            );
        }

        return $this->get($network);
    }

    public function get(string $network): NetworkProfile
    {
        $configuration = $this->configuration();
        $profiles = $configuration['profiles'] ?? null;
        $profile = is_array($profiles) ? ($profiles[$network] ?? null) : null;

        if (! is_array($profile)) {
            throw new NetworkConfigurationException(
                'Unsupported blockchain network.'
            );
        }

        return $this->hydrate($network, $profile);
    }

    public function assertPaymentExecutionAllowed(
        ?NetworkProfile $profile = null
    ): NetworkProfile {
        $profile ??= $this->active();

        if (! $profile->paymentExecutionEnabled) {
            throw new NetworkExecutionDisabledException(
                'Payment execution is disabled for this blockchain network.'
            );
        }

        return $profile;
    }

    public function assertFeeDelegationExecutionAllowed(
        ?NetworkProfile $profile = null
    ): NetworkProfile {
        $profile ??= $this->active();

        if (! $profile->feeDelegationExecutionEnabled) {
            throw new NetworkExecutionDisabledException(
                'Fee delegation is disabled for this blockchain network.'
            );
        }

        return $profile;
    }

    /** @return array<string, mixed> */
    private function configuration(): array
    {
        $configuration = $this->configuration
            ?? config('blockchain');

        if (! is_array($configuration)) {
            throw new NetworkConfigurationException(
                'Blockchain configuration is unavailable.'
            );
        }

        return $configuration;
    }

    /** @param array<string, mixed> $profile */
    private function hydrate(string $id, array $profile): NetworkProfile
    {
        $native = $profile['native_currency'] ?? null;
        $jpyc = $profile['jpyc'] ?? null;

        if (! is_array($native) || ! is_array($jpyc)) {
            throw new NetworkConfigurationException(
                'Blockchain profile metadata is invalid.'
            );
        }

        $version = $this->positiveInteger($profile['version'] ?? null);
        $chainId = $this->positiveInteger($profile['chain_id'] ?? null);
        $currencyDecimals = $this->byteInteger($native['decimals'] ?? null);
        $jpycDecimals = $this->byteInteger($jpyc['decimals'] ?? null);
        $rpcUrl = $this->url($profile['rpc_url'] ?? null, allowLocalHttp: true);
        $secondaryRpcUrl = isset($profile['secondary_rpc_url'])
            ? $this->url($profile['secondary_rpc_url'], allowLocalHttp: true)
            : null;
        $explorerUrl = $this->url(
            $profile['explorer_url'] ?? null,
            allowLocalHttp: false
        );
        $contract = $jpyc['contract'] ?? null;

        if (! is_string($contract)
            || preg_match('/\A0x[0-9a-f]{40}\z/', $contract) !== 1) {
            throw new NetworkConfigurationException(
                'JPYC contract configuration is invalid.'
            );
        }

        $chainName = $this->nonEmptyString($profile['chain_name'] ?? null);
        $currencyName = $this->nonEmptyString($native['name'] ?? null);
        $currencySymbol = $this->nonEmptyString($native['symbol'] ?? null);
        $jpycSymbol = $this->nonEmptyString($jpyc['symbol'] ?? null);
        $testnet = $profile['testnet'] ?? null;
        $paymentEnabled = $profile['payment_execution_enabled'] ?? null;
        $feeDelegationEnabled = $profile['fee_delegation_execution_enabled']
            ?? null;

        if (! is_bool($testnet)
            || ! is_bool($paymentEnabled)
            || ! is_bool($feeDelegationEnabled)
            || ($id === 'kaia-mainnet'
                && ($paymentEnabled || $feeDelegationEnabled))) {
            throw new NetworkConfigurationException(
                'Blockchain execution policy is invalid.'
            );
        }

        return new NetworkProfile(
            id: $id,
            version: $version,
            chainId: $chainId,
            chainName: $chainName,
            rpcUrl: $rpcUrl,
            secondaryRpcUrl: $secondaryRpcUrl,
            explorerUrl: $explorerUrl,
            currencyName: $currencyName,
            currencySymbol: $currencySymbol,
            currencyDecimals: $currencyDecimals,
            jpycContract: $contract,
            jpycSymbol: $jpycSymbol,
            jpycDecimals: $jpycDecimals,
            testnet: $testnet,
            paymentExecutionEnabled: $paymentEnabled,
            feeDelegationExecutionEnabled: $feeDelegationEnabled,
        );
    }

    private function positiveInteger(mixed $value): int
    {
        if ((! is_int($value) && ! is_string($value))
            || preg_match('/\A[0-9]+\z/', (string) $value) !== 1) {
            throw new NetworkConfigurationException(
                'Blockchain numeric configuration is invalid.'
            );
        }

        $number = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (! is_int($number)) {
            throw new NetworkConfigurationException(
                'Blockchain numeric configuration is invalid.'
            );
        }

        return $number;
    }

    private function byteInteger(mixed $value): int
    {
        if ((! is_int($value) && ! is_string($value))
            || preg_match('/\A[0-9]+\z/', (string) $value) !== 1) {
            throw new NetworkConfigurationException(
                'Blockchain decimals configuration is invalid.'
            );
        }

        $number = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 255]]
        );

        if (! is_int($number)) {
            throw new NetworkConfigurationException(
                'Blockchain decimals configuration is invalid.'
            );
        }

        return $number;
    }

    private function nonEmptyString(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new NetworkConfigurationException(
                'Blockchain string configuration is invalid.'
            );
        }

        return $value;
    }

    private function url(mixed $value, bool $allowLocalHttp): string
    {
        if (! is_string($value)
            || filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new NetworkConfigurationException(
                'Blockchain URL configuration is invalid.'
            );
        }

        $parts = parse_url($value);

        if (! is_array($parts)) {
            throw new NetworkConfigurationException(
                'Blockchain URL configuration is invalid.'
            );
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $local = in_array($host, [
            'localhost',
            '127.0.0.1',
            '::1',
            '[::1]',
        ], true);

        if (isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || ($scheme !== 'https'
                && ! ($allowLocalHttp && $scheme === 'http' && $local))) {
            throw new NetworkConfigurationException(
                'Blockchain URL configuration is invalid.'
            );
        }

        return $value;
    }
}
