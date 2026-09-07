<?php

namespace App\Blockchain;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

final class MainnetReadinessChecker
{
    public const CHAIN_ID = 8217;

    public const JPYC_CONTRACT = '0xe7c3d8c9a439fede00d2600032d5db0be71c3c29';

    private const DECIMALS_SELECTOR = '0x313ce567';

    private const SYMBOL_SELECTOR = '0x95d89b41';

    public function __construct(private readonly NetworkProfileRegistry $networks) {}

    public function check(): MainnetReadinessReport
    {
        try {
            $profile = $this->networks->active();
            $this->assertSafeConfiguration($profile);
        } catch (Throwable $exception) {
            $code = $exception instanceof MainnetReadinessException
                ? $exception->diagnosticCode
                : 'mainnet_configuration_invalid';

            return new MainnetReadinessReport(
                false,
                $code,
                $this->notChecked($code),
                $this->notChecked($code),
            );
        }

        $primary = $this->checkProvider($profile->rpcUrl, $profile);
        $secondary = $profile->secondaryRpcUrl === null
            ? $this->notChecked('secondary_rpc_missing')
            : $this->checkProvider($profile->secondaryRpcUrl, $profile);

        if (! $primary['ready'] || ! $secondary['ready']) {
            return new MainnetReadinessReport(
                false,
                ! $primary['ready']
                    ? $primary['diagnostic_code']
                    : $secondary['diagnostic_code'],
                $primary,
                $secondary,
            );
        }

        $maximumLag = $this->nonNegativeInteger(
            config('blockchain.mainnet_readiness_max_block_lag')
        );

        if ($maximumLag === null
            || abs($primary['latest_block'] - $secondary['latest_block']) > $maximumLag) {
            return new MainnetReadinessReport(
                false,
                'provider_height_divergence',
                $primary,
                $secondary,
            );
        }

        return new MainnetReadinessReport(true, null, $primary, $secondary);
    }

    private function assertSafeConfiguration(NetworkProfile $profile): void
    {
        if ($profile->id !== 'kaia-mainnet'
            || $profile->chainId !== self::CHAIN_ID
            || $profile->jpycContract !== self::JPYC_CONTRACT
            || $profile->jpycDecimals !== 18
            || $profile->jpycSymbol !== 'JPYC') {
            throw new MainnetReadinessException('mainnet_profile_mismatch');
        }

        if ($profile->paymentExecutionEnabled
            || $profile->feeDelegationExecutionEnabled
            || config('blockchain.payments_mainnet_enabled') !== false
            || config('blockchain.mainnet_fee_delegation_enabled') !== false
            || config('blockchain.mainnet_broadcast_enabled') !== false) {
            throw new MainnetReadinessException('mainnet_execution_gate_open');
        }
    }

    /** @return array{ready: bool, diagnostic_code: ?string, chain_id: ?int, latest_block: ?int} */
    private function checkProvider(string $rpcUrl, NetworkProfile $profile): array
    {
        try {
            $chainId = $this->quantity($this->rpc($rpcUrl, 'eth_chainId'));

            if ($chainId !== self::CHAIN_ID) {
                throw new MainnetReadinessException('rpc_chain_mismatch');
            }

            $code = $this->rpc($rpcUrl, 'eth_getCode', [self::JPYC_CONTRACT, 'latest']);

            if (! is_string($code)
                || preg_match('/\A0x(?:[0-9a-fA-F]{2})+\z/', $code) !== 1
                || preg_match('/\A0x0+\z/i', $code) === 1) {
                throw new MainnetReadinessException('jpyc_bytecode_missing');
            }

            $decimals = $this->quantity($this->rpc($rpcUrl, 'eth_call', [[
                'to' => self::JPYC_CONTRACT,
                'data' => self::DECIMALS_SELECTOR,
            ], 'latest']));

            if ($decimals !== $profile->jpycDecimals) {
                throw new MainnetReadinessException('jpyc_decimals_mismatch');
            }

            $symbol = $this->abiString($this->rpc($rpcUrl, 'eth_call', [[
                'to' => self::JPYC_CONTRACT,
                'data' => self::SYMBOL_SELECTOR,
            ], 'latest']));

            if ($symbol !== $profile->jpycSymbol) {
                throw new MainnetReadinessException('jpyc_symbol_mismatch');
            }

            $block = $this->rpc($rpcUrl, 'eth_getBlockByNumber', ['latest', false]);
            $blockNumber = is_array($block)
                ? $this->quantity($block['number'] ?? null)
                : null;
            $blockHash = is_array($block) ? ($block['hash'] ?? null) : null;
            $timestamp = is_array($block)
                ? $this->quantity($block['timestamp'] ?? null)
                : null;

            if ($blockNumber === null
                || $blockNumber < 1
                || ! is_string($blockHash)
                || preg_match('/\A0x[0-9a-fA-F]{64}\z/', $blockHash) !== 1
                || $timestamp === null
                || $timestamp < 1) {
                throw new MainnetReadinessException('latest_block_malformed');
            }

            return [
                'ready' => true,
                'diagnostic_code' => null,
                'chain_id' => $chainId,
                'latest_block' => $blockNumber,
            ];
        } catch (MainnetReadinessException $exception) {
            return [
                'ready' => false,
                'diagnostic_code' => $exception->diagnosticCode,
                'chain_id' => null,
                'latest_block' => null,
            ];
        } catch (Throwable) {
            return $this->notChecked('rpc_unavailable');
        }
    }

    private function rpc(string $rpcUrl, string $method, array $parameters = []): mixed
    {
        try {
            $response = Http::timeout(15)->post($rpcUrl, [
                'jsonrpc' => '2.0',
                'method' => $method,
                'params' => $parameters,
                'id' => 1,
            ]);
        } catch (ConnectionException) {
            throw new MainnetReadinessException('rpc_unavailable');
        }

        return $this->result($response);
    }

    private function result(Response $response): mixed
    {
        if (! $response->successful()) {
            throw new MainnetReadinessException('rpc_unavailable');
        }

        try {
            $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new MainnetReadinessException('rpc_response_malformed');
        }

        if (! is_array($body)
            || array_key_exists('error', $body)
            || ! array_key_exists('result', $body)) {
            throw new MainnetReadinessException('rpc_method_unavailable');
        }

        return $body['result'];
    }

    private function quantity(mixed $value): ?int
    {
        if (! is_string($value)
            || preg_match('/\A0x[0-9a-fA-F]+\z/', $value) !== 1) {
            return null;
        }

        try {
            $number = gmp_init(substr($value, 2), 16);
        } catch (Throwable) {
            return null;
        }

        return gmp_cmp($number, PHP_INT_MAX) <= 0
            ? (int) gmp_strval($number, 10)
            : null;
    }

    private function abiString(mixed $value): ?string
    {
        if (! is_string($value)
            || preg_match('/\A0x[0-9a-fA-F]+\z/', $value) !== 1) {
            return null;
        }

        $hex = substr($value, 2);

        if (strlen($hex) < 128 || strlen($hex) % 64 !== 0) {
            return null;
        }

        $offset = $this->quantity('0x'.substr($hex, 0, 64));

        if ($offset === null || $offset > intdiv(strlen($hex), 2) - 32) {
            return null;
        }

        $lengthPosition = $offset * 2;
        $length = $this->quantity('0x'.substr($hex, $lengthPosition, 64));

        if ($length === null || $length < 1 || $length > 64) {
            return null;
        }

        $data = substr($hex, $lengthPosition + 64, $length * 2);

        if (strlen($data) !== $length * 2) {
            return null;
        }

        $decoded = hex2bin($data);

        return is_string($decoded) && preg_match('/\A[A-Za-z0-9._-]+\z/', $decoded) === 1
            ? $decoded
            : null;
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        if ((! is_int($value) && ! is_string($value))
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/', (string) $value) !== 1) {
            return null;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);

        return is_int($number) ? $number : null;
    }

    /** @return array{ready: false, diagnostic_code: string, chain_id: null, latest_block: null} */
    private function notChecked(string $code): array
    {
        return [
            'ready' => false,
            'diagnostic_code' => $code,
            'chain_id' => null,
            'latest_block' => null,
        ];
    }
}
