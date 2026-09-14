<?php

namespace App\Blockchain;

use App\Payments\MainnetPilotLimits;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MainnetPilotRpc
{
    public function __construct(private readonly NetworkProfileRegistry $networks) {}

    /** Observe the same canonical block on both providers. No fallback or RPC writes. */
    public function balance(string $address): array
    {
        [$primary, $secondary] = $this->providers();
        $heights = array_map(fn ($url) => $this->quantity($this->rpc($url, 'eth_blockNumber')), [$primary, $secondary]);
        $primaryLower = bccomp($heights[0], $heights[1], 0) <= 0;
        $height = $primaryLower ? $heights[0] : $heights[1];
        $lag = bcsub($primaryLower ? $heights[1] : $heights[0], $height, 0);
        $maxLag = MainnetPilotLimits::integer(config('blockchain.mainnet_readiness_max_block_lag'));
        if (bccomp($lag, $maxLag, 0) > 0) {
            throw new RuntimeException('pilot_rpc_height_divergence');
        }
        $tag = '0x'.gmp_strval(gmp_init($height, 10), 16);
        $hash = null;
        $balances = [];
        foreach ([$primary, $secondary] as $url) {
            $block = $this->rpc($url, 'eth_getBlockByNumber', [$tag, false]);
            if (! is_array($block) || $this->quantity($block['number'] ?? null) !== $height
                || ! is_string($block['hash'] ?? null) || preg_match('/\A0x[0-9a-fA-F]{64}\z/', $block['hash']) !== 1
                || ($hash !== null && $hash !== strtolower($block['hash']))) {
                throw new RuntimeException('pilot_rpc_canonical_block_mismatch');
            }
            $hash = strtolower($block['hash']);
            $balances[] = $this->quantity($this->rpc($url, 'eth_getBalance', [$address, $tag]));
        }
        if ($balances[0] !== $balances[1]) {
            throw new RuntimeException('pilot_rpc_balance_disagreement');
        }

        return ['balance_wei' => $balances[0], 'balance_block_number' => $height, 'balance_block_hash' => $hash];
    }

    /** Match the wallet's unsigned ERC20 estimate + its explicit delegated intrinsic overhead. */
    public function gas(string $sender, string $merchant): array
    {
        $profile = $this->networks->active();
        $data = '0xa9059cbb'.str_pad(substr($merchant, 2), 64, '0', STR_PAD_LEFT)
            .str_pad(gmp_strval(gmp_init('1000000000000000000', 10), 16), 64, '0', STR_PAD_LEFT);
        $estimates = [];
        $prices = [];
        foreach ($this->providers() as $url) {
            $estimates[] = $this->quantity($this->rpc($url, 'eth_estimateGas', [[
                'from' => $sender, 'to' => $profile->jpycContract, 'data' => $data, 'value' => '0x0',
            ]]));
            $prices[] = $this->quantity($this->rpc($url, 'eth_gasPrice'));
        }
        $estimate = bccomp($estimates[0], $estimates[1], 0) >= 0 ? $estimates[0] : $estimates[1];
        $price = bccomp($prices[0], $prices[1], 0) >= 0 ? $prices[0] : $prices[1];
        if (bccomp($estimate, '0', 0) <= 0 || bccomp($price, '0', 0) <= 0) {
            throw new RuntimeException('pilot_gas_observation_invalid');
        }

        return [
            'primary_estimated_execution_gas' => $estimates[0],
            'secondary_estimated_execution_gas' => $estimates[1],
            'fee_delegation_intrinsic_gas' => '10000',
            'observed_required_gas' => bcadd($estimate, '10000', 0),
            'primary_gas_price_wei' => $prices[0], 'secondary_gas_price_wei' => $prices[1],
            'observed_gas_price_wei' => $price,
        ];
    }

    private function providers(): array
    {
        $profile = $this->networks->active();
        if ($profile->id !== 'kaia-mainnet' || $profile->chainId !== 8217
            || $profile->secondaryRpcUrl === null || $profile->rpcUrl === $profile->secondaryRpcUrl) {
            throw new RuntimeException('pilot_rpc_configuration_invalid');
        }
        $urls = [$profile->rpcUrl, $profile->secondaryRpcUrl];
        foreach ($urls as $url) {
            if ($this->quantity($this->rpc($url, 'eth_chainId')) !== '8217') {
                throw new RuntimeException('pilot_rpc_chain_mismatch');
            }
        }

        return $urls;
    }

    private function rpc(string $url, string $method, array $params = []): mixed
    {
        // This allowlist is deliberately independent from all signing/broadcast transports.
        if (! in_array($method, ['eth_chainId', 'eth_blockNumber', 'eth_getBlockByNumber',
            'eth_getBalance', 'eth_estimateGas', 'eth_gasPrice'], true)) {
            throw new RuntimeException('pilot_rpc_method_forbidden');
        }
        $response = Http::timeout(15)->withOptions(['allow_redirects' => false])->post($url, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params,
        ]);
        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ($body['jsonrpc'] ?? null) !== '2.0'
            || ($body['id'] ?? null) !== 1 || array_key_exists('error', $body) || ! array_key_exists('result', $body)) {
            throw new RuntimeException('pilot_rpc_unavailable_or_malformed');
        }

        return $body['result'];
    }

    private function quantity(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A0x(?:0|[1-9a-fA-F][0-9a-fA-F]{0,63})\z/', $value) !== 1) {
            throw new RuntimeException('pilot_rpc_quantity_invalid');
        }

        return gmp_strval(gmp_init(substr($value, 2), 16), 10);
    }
}
