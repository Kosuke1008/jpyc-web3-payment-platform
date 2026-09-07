<?php

namespace Tests\Feature;

use App\Blockchain\MainnetStagingInfrastructure;
use App\Blockchain\MainnetStagingReadinessChecker;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MainnetStagingReadinessTest extends TestCase
{
    private const PRIMARY = 'https://mainnet-primary.example.test';

    private const SECONDARY = 'https://mainnet-secondary.example.test';

    private const MERCHANT = '0x1111111111111111111111111111111111111111';

    private const FEE_PAYER = '0x2222222222222222222222222222222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'blockchain.network' => 'kaia-mainnet',
            'blockchain.profiles.kaia-mainnet.rpc_url' => self::PRIMARY,
            'blockchain.profiles.kaia-mainnet.secondary_rpc_url' => self::SECONDARY,
            'blockchain.profiles.kairos.rpc_url' => 'https://kairos.example.test',
            'blockchain.payments_mainnet_enabled' => false,
            'blockchain.mainnet_fee_delegation_enabled' => false,
            'blockchain.mainnet_broadcast_enabled' => false,
            'blockchain.mainnet_readiness_max_block_lag' => 10,
            'services.fee_delegation.mode' => 'self-hosted',
            'services.fee_delegation.self_hosted_mainnet.enabled' => false,
            'services.fee_delegation.self_hosted_mainnet.kill_switch' => true,
            'services.fee_delegation.mainnet_staging' => [
                'environment_id' => 'mainnet-staging',
                'database_identifier' => 'livt_mainnet_staging',
                'kairos_database_identifier' => 'livt_kairos',
                'cache_prefix' => 'livt-mainnet-staging-cache-',
                'kairos_cache_prefix' => 'livt-kairos-cache-',
                'merchant_address' => self::MERCHANT,
                'kairos_merchant_address' => '0x3333333333333333333333333333333333333333',
                'fee_payer_address' => self::FEE_PAYER,
                'kairos_fee_payer_address' => '0x4444444444444444444444444444444444444444',
                'approved_user_ids' => '7',
                'approved_sender_addresses' => '0x5555555555555555555555555555555555555555',
                'fee_payer_health_url' => 'http://127.0.0.1:19000/health',
                'stage2_legacy_policy_resolved' => false,
                'allow_cross_environment_reuse' => false,
            ],
        ]);

        $this->useInfrastructure();
        $this->fakeReadOnlyServices();
        Http::preventStrayRequests();
    }

    public function test_reports_read_only_ready_with_signer_unavailable(): void
    {
        $report = app(MainnetStagingReadinessChecker::class)->check();

        $this->assertTrue($report->ready);
        $this->assertSame('READ_ONLY_READY', $report->toArray()['overall']);
        $this->assertSame('NOT_READY', $report->toArray()['signing']);
        $this->assertSame('DISABLED', $report->toArray()['broadcast']);
        $this->assertSame(self::MERCHANT, $report->publicContext['merchant_address']);
        $this->assertSame(self::FEE_PAYER, $report->publicContext['fee_payer_address']);

        $this->artisan('blockchain:mainnet-staging-readiness')
            ->expectsOutputToContain('READ_ONLY_READY')
            ->assertSuccessful();

        Http::assertNotSent(fn (Request $request): bool => in_array(
            $request['method'] ?? null,
            ['eth_sendRawTransaction', 'kaia_sendRawTransaction'],
            true
        ));
    }

    public function test_wrong_database_and_process_local_cache_are_rejected(): void
    {
        $this->useInfrastructure(
            database: 'livt_kairos',
            cacheReady: false,
            cacheDriver: 'array'
        );

        $report = app(MainnetStagingReadinessChecker::class)->check();

        $this->assertFalse($report->ready);
        $this->assertFalse($report->checks['database']['ready']);
        $this->assertFalse($report->checks['cache_lock']['ready']);
    }

    public function test_kairos_or_public_rpc_cannot_be_mainnet_staging_rpc(): void
    {
        foreach ([
            'https://kairos.example.test',
            'https://public-en.node.kaia.io',
        ] as $url) {
            config(['blockchain.profiles.kaia-mainnet.rpc_url' => $url]);
            $report = app(MainnetStagingReadinessChecker::class)->check();
            $this->assertFalse($report->checks['rpc_separation']['ready']);
        }
    }

    public function test_wrong_rpc_chain_fails_read_only_readiness(): void
    {
        Http::swap(new Factory);
        $this->fakeReadOnlyServices(chainId: '0x3e9');

        $report = app(MainnetStagingReadinessChecker::class)->check();

        $this->assertFalse($report->ready);
        $this->assertFalse($report->checks['primary_rpc']['ready']);
    }

    public function test_stage2_blocker_is_visible_without_blocking_read_only_state(): void
    {
        $this->useInfrastructure(includeStage2: false);

        $report = app(MainnetStagingReadinessChecker::class)->check();

        $this->assertTrue($report->ready);
        $this->assertSame(
            'blocked_pending_legacy_policy',
            $report->checks['stage2_hardening']['diagnostic']
        );
    }

    public function test_reused_identity_or_open_execution_gate_fails(): void
    {
        config([
            'services.fee_delegation.mainnet_staging.kairos_merchant_address' => self::MERCHANT,
            'blockchain.mainnet_broadcast_enabled' => true,
        ]);

        $report = app(MainnetStagingReadinessChecker::class)->check();

        $this->assertFalse($report->checks['identity_separation']['ready']);
        $this->assertFalse($report->checks['execution_gates']['ready']);
    }

    private function useInfrastructure(
        string $database = 'livt_mainnet_staging',
        bool $cacheReady = true,
        string $cacheDriver = 'redis',
        bool $includeStage2 = true
    ): void {
        $migrations = [
            '2026_09_04_000000_add_payment_snapshot_to_payments_table',
            '2026_09_06_000000_add_confirmation_evidence_to_payments_table',
            '2026_09_06_500000_create_payment_fee_delegation_attempts_table',
        ];
        if ($includeStage2) {
            $migrations[] = '2026_09_07_000000_harden_payments_for_chain_aware_uniqueness';
        }

        $this->app->instance(
            MainnetStagingInfrastructure::class,
            new class($database, $cacheReady, $cacheDriver, $migrations) extends MainnetStagingInfrastructure
            {
                public function __construct(
                    private readonly string $databaseName,
                    private readonly bool $cacheReady,
                    private readonly string $cacheDriver,
                    private readonly array $migrations
                ) {}

                public function database(): array
                {
                    return [
                        'ready' => true,
                        'name' => $this->databaseName,
                        'migrations' => $this->migrations,
                    ];
                }

                public function cacheLock(): array
                {
                    return [
                        'ready' => $this->cacheReady,
                        'driver' => $this->cacheDriver,
                        'prefix' => 'livt-mainnet-staging-cache-',
                    ];
                }
            }
        );
    }

    private function fakeReadOnlyServices(string $chainId = '0x2019'): void
    {
        Http::fake(function (Request $request) use ($chainId) {
            if ($request->url() === 'http://127.0.0.1:19000/health') {
                return Http::response([
                    'status' => 'STRUCTURALLY_READY',
                    'network' => 'kaia-mainnet',
                    'chain_id' => 8217,
                    'fee_payer_address' => self::FEE_PAYER,
                    'balance_kaia' => '0.2',
                    'signer_status' => 'UNAVAILABLE',
                    'kill_switch' => 'ACTIVE',
                    'execution' => 'DISABLED',
                    'signing' => 'DISABLED',
                    'broadcast' => 'DISABLED',
                ]);
            }

            $result = match ($request['method']) {
                'eth_chainId' => $chainId,
                'eth_getCode' => '0x6000',
                'eth_call' => $request['params'][0]['data'] === '0x313ce567'
                    ? '0x'.str_pad(dechex(18), 64, '0', STR_PAD_LEFT)
                    : $this->abiString('JPYC'),
                'eth_getBlockByNumber' => [
                    'number' => '0x64',
                    'hash' => '0x'.str_repeat('a', 64),
                    'timestamp' => '0x65000000',
                ],
                default => null,
            };

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => $result,
            ]);
        });
    }

    private function abiString(string $value): string
    {
        return '0x'
            .str_pad('20', 64, '0', STR_PAD_LEFT)
            .str_pad(dechex(strlen($value)), 64, '0', STR_PAD_LEFT)
            .str_pad(bin2hex($value), 64, '0');
    }
}
