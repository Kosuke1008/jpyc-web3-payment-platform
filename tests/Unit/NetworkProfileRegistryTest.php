<?php

namespace Tests\Unit;

use App\Blockchain\NetworkConfigurationException;
use App\Blockchain\NetworkExecutionDisabledException;
use App\Blockchain\NetworkProfileRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NetworkProfileRegistryTest extends TestCase
{
    private const JPYC = '0xe7c3d8c9a439fede00d2600032d5db0be71c3c29';

    public function test_kairos_profile_resolves_and_keeps_execution_available(): void
    {
        $registry = new NetworkProfileRegistry($this->configuration('kairos'));
        $profile = $registry->active();

        $this->assertSame('kairos', $profile->id);
        $this->assertSame(1001, $profile->chainId);
        $this->assertSame(self::JPYC, $profile->jpycContract);
        $this->assertNull($profile->secondaryRpcUrl);
        $this->assertTrue($profile->testnet);
        $this->assertSame(
            'kairos',
            $registry->assertPaymentExecutionAllowed()->id
        );
        $this->assertSame(
            'kairos',
            $registry->assertFeeDelegationExecutionAllowed()->id
        );
    }

    public function test_mainnet_profile_resolves_but_execution_is_disabled(): void
    {
        $configuration = $this->configuration('kaia-mainnet');
        $configuration['payments_mainnet_enabled'] = true;
        $registry = new NetworkProfileRegistry($configuration);
        $profile = $registry->active();

        $this->assertSame('kaia-mainnet', $profile->id);
        $this->assertSame(8217, $profile->chainId);
        $this->assertSame(self::JPYC, $profile->jpycContract);
        $this->assertSame(
            'https://mainnet-secondary.example.test',
            $profile->secondaryRpcUrl
        );
        $this->assertFalse($profile->testnet);

        $this->expectException(NetworkExecutionDisabledException::class);
        $registry->assertPaymentExecutionAllowed($profile);
    }

    public function test_mainnet_fee_delegation_is_disabled(): void
    {
        $registry = new NetworkProfileRegistry(
            $this->configuration('kaia-mainnet')
        );

        $this->expectException(NetworkExecutionDisabledException::class);
        $registry->assertFeeDelegationExecutionAllowed();
    }

    #[DataProvider('invalidSelectorProvider')]
    public function test_missing_malformed_or_unsupported_network_is_rejected(
        mixed $network
    ): void {
        $registry = new NetworkProfileRegistry($this->configuration($network));

        $this->expectException(NetworkConfigurationException::class);
        $registry->active();
    }

    /** @return array<string, array{mixed}> */
    public static function invalidSelectorProvider(): array
    {
        return [
            'missing' => [null],
            'uppercase' => ['KAIROS'],
            'numeric alias' => ['8217'],
            'unsupported alias' => ['mainnet'],
            'unsupported well formed' => ['another-network'],
        ];
    }

    public function test_missing_rpc_is_rejected(): void
    {
        $configuration = $this->configuration('kairos');
        $configuration['profiles']['kairos']['rpc_url'] = null;

        $this->expectException(NetworkConfigurationException::class);
        (new NetworkProfileRegistry($configuration))->active();
    }

    public function test_incorrect_chain_id_configuration_is_rejected(): void
    {
        $configuration = $this->configuration('kairos');
        $configuration['profiles']['kairos']['chain_id'] = '1001.0';

        $this->expectException(NetworkConfigurationException::class);
        (new NetworkProfileRegistry($configuration))->active();
    }

    public function test_wrong_jpyc_contract_configuration_is_rejected(): void
    {
        $configuration = $this->configuration('kairos');
        $configuration['profiles']['kairos']['jpyc']['contract'] =
            '0xE7C3D8C9a439feDe00D2600032D5dB0Be71C3c29';

        $this->expectException(NetworkConfigurationException::class);
        (new NetworkProfileRegistry($configuration))->active();
    }

    public function test_application_environments_do_not_select_the_network(): void
    {
        $configuration = $this->configuration('kairos');
        $configuration['APP_ENV'] = 'production';
        $configuration['NODE_ENV'] = 'production';

        $this->assertSame(
            'kairos',
            (new NetworkProfileRegistry($configuration))->active()->id
        );
    }

    public function test_mainnet_profile_cannot_be_configured_as_executable(): void
    {
        $configuration = $this->configuration('kaia-mainnet');
        $configuration['profiles']['kaia-mainnet']['payment_execution_enabled'] =
            true;

        $this->expectException(NetworkConfigurationException::class);
        (new NetworkProfileRegistry($configuration))->active();
    }

    /** @return array<string, mixed> */
    private function configuration(mixed $network): array
    {
        $profile = static fn (
            int $chainId,
            string $name,
            string $rpc,
            string $explorer,
            bool $testnet,
            bool $payments,
            bool $feeDelegation
        ): array => [
            'version' => 1,
            'chain_id' => $chainId,
            'chain_name' => $name,
            'rpc_url' => $rpc,
            'secondary_rpc_url' => $chainId === 8217
                ? 'https://mainnet-secondary.example.test'
                : null,
            'explorer_url' => $explorer,
            'native_currency' => [
                'name' => 'KAIA',
                'symbol' => 'KAIA',
                'decimals' => 18,
            ],
            'jpyc' => [
                'contract' => self::JPYC,
                'symbol' => 'JPYC',
                'decimals' => 18,
            ],
            'testnet' => $testnet,
            'payment_execution_enabled' => $payments,
            'fee_delegation_execution_enabled' => $feeDelegation,
        ];

        return [
            'network' => $network,
            'payments_mainnet_enabled' => false,
            'profiles' => [
                'kairos' => $profile(
                    1001,
                    'Kaia Kairos Testnet',
                    'https://kairos.example.test',
                    'https://kairos.kaiascan.io',
                    true,
                    true,
                    true
                ),
                'kaia-mainnet' => $profile(
                    8217,
                    'Kaia Mainnet',
                    'https://mainnet.example.test',
                    'https://kaiascan.io',
                    false,
                    false,
                    false
                ),
            ],
        ];
    }
}
