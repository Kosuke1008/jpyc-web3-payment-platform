<?php

namespace App\Blockchain;

use App\Services\Payments\FeeDelegationEndpoint;
use App\Services\Payments\ManagedKairosLiveTestPolicy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class KairosManagedReadinessChecker
{
    private const MAX_RESPONSE_BYTES = 1_048_576;

    public function __construct(
        private readonly NetworkProfileRegistry $networks
    ) {}

    public function check(): KairosManagedReadinessReport
    {
        $checks = [];

        try {
            $profile = $this->networks->active();
            $this->record($checks, 'active_network', $profile->id === 'kairos', 'network_not_kairos');
            $this->record($checks, 'profile_chain_id', $profile->chainId === 1001, 'profile_chain_id_mismatch');
            $this->record(
                $checks,
                'jpyc_profile',
                $profile->jpycContract === '0xe7c3d8c9a439fede00d2600032d5db0be71c3c29'
                    && $profile->jpycSymbol === 'JPYC'
                    && $profile->jpycDecimals === 18,
                'jpyc_profile_mismatch'
            );
        } catch (Throwable) {
            $profile = null;
            $this->record($checks, 'active_network', false, 'network_configuration_invalid');
            $this->record($checks, 'profile_chain_id', false, 'network_configuration_invalid');
            $this->record($checks, 'jpyc_profile', false, 'network_configuration_invalid');
        }

        $mode = config('services.fee_delegation.mode');
        $enabled = config('services.fee_delegation.kairos_managed_live_test_enabled');
        $endpoint = config('services.fee_delegation.url');
        $apiKey = config('services.fee_delegation.api_key');
        $paymentId = config('services.fee_delegation.kairos_managed_live_test_payment_id');
        $maximum = config('services.fee_delegation.kairos_managed_live_test_max_jpy');

        $this->record($checks, 'provider_mode', $mode === 'kaia-managed', 'provider_mode_not_managed');
        $this->record(
            $checks,
            'fee_delegation_gate',
            config('services.fee_delegation.enabled') === true,
            'fee_delegation_disabled'
        );
        $this->record($checks, 'live_test_gate', $enabled === true, 'live_test_gate_disabled');
        $this->record(
            $checks,
            'selected_payment',
            $this->positiveInteger($paymentId),
            'selected_payment_missing'
        );
        $this->record(
            $checks,
            'managed_endpoint',
            is_string($endpoint)
                && rtrim($endpoint, '/') === ManagedKairosLiveTestPolicy::ENDPOINT
                && FeeDelegationEndpoint::isManaged($endpoint, $apiKey),
            'managed_kairos_endpoint_invalid'
        );
        $this->record(
            $checks,
            'backend_api_key',
            is_string($apiKey) && $apiKey !== '',
            'backend_api_key_missing'
        );
        $this->record(
            $checks,
            'frontend_key_isolation',
            $this->frontendSecretIsolated($apiKey),
            'api_key_exposed_in_public_config'
        );
        $this->record(
            $checks,
            'test_maximum',
            $this->positiveInteger($maximum)
                && (int) $maximum <= ManagedKairosLiveTestPolicy::MAXIMUM_FIRST_TEST_JPYC,
            'test_maximum_invalid'
        );
        $this->record(
            $checks,
            'mainnet_gates',
            config('blockchain.payments_mainnet_enabled') === false
                && config('blockchain.mainnet_fee_delegation_enabled') === false
                && config('blockchain.mainnet_broadcast_enabled') === false,
            'mainnet_gate_open'
        );
        $this->record(
            $checks,
            'provider_fallback',
            $mode === 'kaia-managed',
            'provider_selection_unsafe'
        );
        $this->record(
            $checks,
            'attempt_ledger',
            Schema::hasTable('payment_fee_delegation_attempts')
                && Schema::hasColumns('payment_fee_delegation_attempts', [
                    'payment_id',
                    'provider',
                    'sender_tx_hash',
                    'request_fingerprint',
                    'state',
                ]),
            'attempt_ledger_missing'
        );

        if ($profile === null || $profile->id !== 'kairos') {
            $this->record($checks, 'rpc_chain_id', false, 'rpc_not_checked');
            $this->record($checks, 'sender_tx_hash_indexing', false, 'rpc_not_checked');
            $this->record($checks, 'latest_block', false, 'rpc_not_checked');
        } else {
            try {
                $chainId = $this->quantity($this->rpc($profile->rpcUrl, 'kaia_chainId'));
                $this->record($checks, 'rpc_chain_id', $chainId === '1001', 'rpc_chain_id_mismatch');

                $indexing = $this->rpc(
                    $profile->rpcUrl,
                    'kaia_isSenderTxHashIndexingEnabled'
                );
                $this->record(
                    $checks,
                    'sender_tx_hash_indexing',
                    $indexing === true,
                    'sender_tx_hash_indexing_disabled'
                );

                $latestBlock = $this->quantity($this->rpc($profile->rpcUrl, 'kaia_blockNumber'));
                $this->record(
                    $checks,
                    'latest_block',
                    $latestBlock !== null,
                    'latest_block_unreadable'
                );
            } catch (Throwable) {
                $this->recordMissingRpcChecks($checks);
            }
        }

        return new KairosManagedReadinessReport(
            ! in_array(false, array_column($checks, 'ready'), true),
            $checks
        );
    }

    private function rpc(string $url, string $method): mixed
    {
        $response = Http::timeout(15)
            ->acceptJson()
            ->withOptions(['allow_redirects' => false])
            ->post($url, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => $method,
                'params' => [],
            ]);

        if (! $response->successful()
            || strlen($response->body()) > self::MAX_RESPONSE_BYTES) {
            throw new NetworkConfigurationException('Kairos RPC unavailable.');
        }

        $body = $response->json();

        if (! is_array($body)
            || array_key_exists('error', $body)
            || ! array_key_exists('result', $body)) {
            throw new NetworkConfigurationException('Kairos RPC malformed.');
        }

        return $body['result'];
    }

    /** @param array<string, array{ready: bool, diagnostic: string}> $checks */
    private function record(
        array &$checks,
        string $name,
        bool $ready,
        string $failureDiagnostic
    ): void {
        $checks[$name] = [
            'ready' => $ready,
            'diagnostic' => $ready ? 'none' : $failureDiagnostic,
        ];
    }

    /** @param array<string, array{ready: bool, diagnostic: string}> $checks */
    private function recordMissingRpcChecks(array &$checks): void
    {
        foreach ([
            'rpc_chain_id' => 'rpc_unavailable',
            'sender_tx_hash_indexing' => 'rpc_unavailable',
            'latest_block' => 'rpc_unavailable',
        ] as $name => $diagnostic) {
            if (! isset($checks[$name])) {
                $this->record($checks, $name, false, $diagnostic);
            }
        }
    }

    private function quantity(mixed $value): ?string
    {
        return is_string($value)
            && preg_match('/\A0x[0-9a-fA-F]+\z/', $value) === 1
                ? gmp_strval(gmp_init(substr($value, 2), 16), 10)
                : null;
    }

    private function positiveInteger(mixed $value): bool
    {
        return (is_int($value) || is_string($value))
            && preg_match('/\A[1-9][0-9]*\z/', (string) $value) === 1
            && filter_var(
                $value,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            ) !== false;
    }

    private function frontendSecretIsolated(mixed $apiKey): bool
    {
        if (! is_string($apiKey) || $apiKey === '') {
            return false;
        }

        foreach ([resource_path(), public_path('build')] as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $directory,
                    \FilesystemIterator::SKIP_DOTS
                )
            );

            foreach ($files as $file) {
                if (! $file->isFile()
                    || $file->isLink()
                    || $file->getSize() > self::MAX_RESPONSE_BYTES) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());

                if (is_string($contents)
                    && (str_contains($contents, $apiKey)
                        || str_contains(
                            $contents,
                            'KAIA_FEE_DELEGATION_API_KEY'
                        ))) {
                    return false;
                }
            }
        }

        return true;
    }
}
