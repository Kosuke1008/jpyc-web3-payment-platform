<?php

namespace Tests\Feature;

use App\Blockchain\MainnetPilotRpc;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class MainnetPilotRpcTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'blockchain.network' => 'kaia-mainnet',
            'blockchain.profiles.kaia-mainnet.rpc_url' => 'https://primary.example.test',
            'blockchain.profiles.kaia-mainnet.secondary_rpc_url' => 'https://secondary.example.test',
            'blockchain.mainnet_readiness_max_block_lag' => 10,
        ]);
        Http::preventStrayRequests();
    }

    public function test_balance_preserves_values_above_safe_integer_and_uses_same_block(): void
    {
        $this->fakeRpc();
        $result = app(MainnetPilotRpc::class)->balance('0x'.str_repeat('1', 40));
        $this->assertSame('9007199254740993', $result['balance_wei']);
        Http::assertSent(fn (Request $request) => $request['method'] === 'eth_getBalance' && $request['params'][1] === '0x64');
    }

    #[DataProvider('invalidRpcScenarios')]
    public function test_invalid_or_disagreeing_providers_fail_closed(string $scenario): void
    {
        $this->fakeRpc($scenario);
        $this->expectException(RuntimeException::class);
        app(MainnetPilotRpc::class)->balance('0x'.str_repeat('1', 40));
    }

    public static function invalidRpcScenarios(): array
    {
        return [['chain'], ['balance'], ['block_hash'], ['block_number'], ['height_lag'], ['malformed_quantity'], ['rpc_error'], ['wrong_id']];
    }

    public function test_gas_observation_matches_wallet_overhead_and_uses_no_signing_rpc(): void
    {
        $this->fakeRpc();
        $result = app(MainnetPilotRpc::class)->gas('0x'.str_repeat('1', 40), '0x'.str_repeat('2', 40));
        $this->assertSame('110000', $result['observed_required_gas']);
        $this->assertSame('25000000000', $result['observed_gas_price_wei']);
        Http::assertNotSent(fn (Request $request) => ! in_array($request['method'], ['eth_chainId', 'eth_estimateGas', 'eth_gasPrice'], true));
    }

    private function fakeRpc(?string $scenario = null): void
    {
        Http::fake(function (Request $request) use ($scenario) {
            $secondary = $request->url() === 'https://secondary.example.test';
            $result = match ($request['method']) {
                'eth_chainId' => $secondary && $scenario === 'chain' ? '0x3e9' : '0x2019',
                'eth_blockNumber' => $secondary && $scenario === 'height_lag' ? '0x50' : '0x64',
                'eth_getBlockByNumber' => [
                    'number' => $secondary && $scenario === 'block_number' ? '0x63' : '0x64',
                    'hash' => '0x'.str_repeat($secondary && $scenario === 'block_hash' ? 'b' : 'a', 64),
                ],
                'eth_getBalance' => match (true) {
                    $scenario === 'malformed_quantity' => '0x01',
                    $secondary && $scenario === 'balance' => '0x1',
                    default => '0x20000000000001',
                },
                'eth_estimateGas' => '0x186a0',
                'eth_gasPrice' => '0x5d21dba00',
                default => null,
            };

            return Http::response($scenario === 'rpc_error' ? ['error' => ['code' => -32000]] : [
                'jsonrpc' => '2.0', 'id' => $scenario === 'wrong_id' ? 9 : 1, 'result' => $result,
            ]);
        });
    }
}
