<?php

namespace Tests\Feature;

use App\Blockchain\MainnetReadinessChecker;
use App\Blockchain\NetworkExecutionDisabledException;
use App\Blockchain\NetworkProfileRegistry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MainnetReadinessTest extends TestCase
{
    private const PRIMARY = 'https://mainnet-primary.example.test';

    private const SECONDARY = 'https://mainnet-secondary.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'blockchain.network' => 'kaia-mainnet',
            'blockchain.profiles.kaia-mainnet.rpc_url' => self::PRIMARY,
            'blockchain.profiles.kaia-mainnet.secondary_rpc_url' => self::SECONDARY,
            'blockchain.payments_mainnet_enabled' => false,
            'blockchain.mainnet_fee_delegation_enabled' => false,
            'blockchain.mainnet_broadcast_enabled' => false,
            'blockchain.mainnet_readiness_max_block_lag' => 10,
        ]);

        Http::preventStrayRequests();
    }

    public function test_correct_mainnet_providers_pass_read_only_readiness(): void
    {
        $this->fakeProviders();

        $report = app(MainnetReadinessChecker::class)->check();

        $this->assertTrue($report->ready);
        $this->assertTrue($report->primary['ready']);
        $this->assertTrue($report->secondary['ready']);
        Http::assertSentCount(10);
        Http::assertSent(fn (Request $request): bool => in_array(
            $request['method'],
            ['eth_chainId', 'eth_getCode', 'eth_call', 'eth_getBlockByNumber'],
            true
        ));
        Http::assertNotSent(fn (Request $request): bool => in_array(
            $request['method'],
            ['eth_sendRawTransaction', 'klay_sendRawTransaction'],
            true
        ));

        foreach (['payment', 'fee_delegation'] as $gate) {
            try {
                $gate === 'payment'
                    ? app(NetworkProfileRegistry::class)->assertPaymentExecutionAllowed()
                    : app(NetworkProfileRegistry::class)->assertFeeDelegationExecutionAllowed();
                $this->fail("Mainnet {$gate} execution was enabled.");
            } catch (NetworkExecutionDisabledException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_kairos_chain_is_rejected_for_mainnet(): void
    {
        $this->fakeProviders(chainId: '0x3e9');

        $report = app(MainnetReadinessChecker::class)->check();

        $this->assertFalse($report->ready);
        $this->assertSame('rpc_chain_mismatch', $report->diagnosticCode);
    }

    public function test_wrong_jpyc_profile_address_is_rejected_before_rpc(): void
    {
        config(['blockchain.profiles.kaia-mainnet.jpyc.contract' => '0x1111111111111111111111111111111111111111']);

        $report = app(MainnetReadinessChecker::class)->check();

        $this->assertFalse($report->ready);
        $this->assertSame('mainnet_profile_mismatch', $report->diagnosticCode);
        Http::assertNothingSent();
    }

    public function test_empty_contract_code_is_rejected(): void
    {
        $this->fakeProviders(code: '0x');

        $this->assertSame(
            'jpyc_bytecode_missing',
            app(MainnetReadinessChecker::class)->check()->diagnosticCode
        );
    }

    public function test_wrong_decimals_are_rejected(): void
    {
        $this->fakeProviders(decimals: 6);

        $this->assertSame(
            'jpyc_decimals_mismatch',
            app(MainnetReadinessChecker::class)->check()->diagnosticCode
        );
    }

    public function test_wrong_symbol_is_rejected(): void
    {
        $this->fakeProviders(symbol: 'WRONG');

        $this->assertSame(
            'jpyc_symbol_mismatch',
            app(MainnetReadinessChecker::class)->check()->diagnosticCode
        );
    }

    public function test_malformed_latest_block_is_rejected(): void
    {
        $this->fakeProviders(malformedBlock: true);

        $this->assertSame(
            'latest_block_malformed',
            app(MainnetReadinessChecker::class)->check()->diagnosticCode
        );
    }

    public function test_primary_or_secondary_unavailability_fails_readiness(): void
    {
        $this->fakeProviders(unavailable: self::PRIMARY);
        $report = app(MainnetReadinessChecker::class)->check();
        $this->assertFalse($report->ready);
        $this->assertSame('rpc_unavailable', $report->primary['diagnostic_code']);

        Http::fake();
        $this->fakeProviders(unavailable: self::SECONDARY);
        $report = app(MainnetReadinessChecker::class)->check();
        $this->assertFalse($report->ready);
        $this->assertSame('rpc_unavailable', $report->secondary['diagnostic_code']);
    }

    public function test_readiness_refuses_any_open_mainnet_execution_gate(): void
    {
        config(['blockchain.payments_mainnet_enabled' => true]);

        $report = app(MainnetReadinessChecker::class)->check();

        $this->assertFalse($report->ready);
        $this->assertSame('mainnet_execution_gate_open', $report->diagnosticCode);
        Http::assertNothingSent();
    }

    private function fakeProviders(
        string $chainId = '0x2019',
        string $code = '0x6001600055',
        int $decimals = 18,
        string $symbol = 'JPYC',
        bool $malformedBlock = false,
        ?string $unavailable = null,
    ): void {
        Http::fake(function (Request $request) use (
            $chainId,
            $code,
            $decimals,
            $symbol,
            $malformedBlock,
            $unavailable,
        ) {
            if ($request->url() === $unavailable) {
                return Http::failedConnection();
            }

            $result = match ($request['method']) {
                'eth_chainId' => $chainId,
                'eth_getCode' => $code,
                'eth_call' => $request['params'][0]['data'] === '0x313ce567'
                    ? '0x'.str_pad(dechex($decimals), 64, '0', STR_PAD_LEFT)
                    : $this->abiString($symbol),
                'eth_getBlockByNumber' => $malformedBlock
                    ? ['number' => null]
                    : [
                        'number' => $request->url() === self::PRIMARY ? '0x64' : '0x62',
                        'hash' => '0x'.str_repeat('a', 64),
                        'timestamp' => '0x65000000',
                    ],
                default => null,
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        });
    }

    private function abiString(string $value): string
    {
        return '0x'
            .str_pad('20', 64, '0', STR_PAD_LEFT)
            .str_pad(dechex(strlen($value)), 64, '0', STR_PAD_LEFT)
            .str_pad(bin2hex($value), 64, '0', STR_PAD_RIGHT);
    }
}
