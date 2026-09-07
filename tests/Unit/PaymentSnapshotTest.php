<?php

namespace Tests\Unit;

use App\Blockchain\NetworkProfile;
use App\Payments\InvalidPaymentSnapshotException;
use App\Payments\PaymentSnapshot;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PaymentSnapshotTest extends TestCase
{
    private const TOKEN = '0xe7c3d8c9a439fede00d2600032d5db0be71c3c29';

    private const RECIPIENT = '0x2222222222222222222222222222222222222222';

    public function test_creation_captures_profile_and_exact_atomic_amount(): void
    {
        $snapshot = PaymentSnapshot::create(
            $this->profile(),
            '0x'.strtoupper(substr(self::RECIPIENT, 2)),
            '100',
            '2026-09-06T12:00:00+09:00'
        );

        $this->assertSame('kairos', $snapshot->network);
        $this->assertSame(1, $snapshot->networkProfileVersion);
        $this->assertSame(1001, $snapshot->chainId);
        $this->assertSame(self::TOKEN, $snapshot->tokenContract);
        $this->assertSame('JPYC', $snapshot->tokenSymbol);
        $this->assertSame(18, $snapshot->tokenDecimals);
        $this->assertSame(self::RECIPIENT, $snapshot->recipientAddress);
        $this->assertSame('100', $snapshot->displayAmount);
        $this->assertSame('100000000000000000000', $snapshot->atomicAmount);
        $this->assertTrue($snapshot->expiresAt->equalTo(
            CarbonImmutable::parse('2026-09-06T03:00:00Z')
        ));
    }

    #[DataProvider('invalidAmountProvider')]
    public function test_creation_rejects_non_canonical_or_out_of_range_amounts(
        mixed $amount
    ): void {
        $this->expectException(InvalidPaymentSnapshotException::class);

        PaymentSnapshot::create(
            $this->profile(),
            self::RECIPIENT,
            $amount,
            '2026-09-06T12:00:00+09:00'
        );
    }

    /** @return array<string, array{mixed}> */
    public static function invalidAmountProvider(): array
    {
        return [
            'null' => [null],
            'zero integer' => [0],
            'zero string' => ['0'],
            'negative' => [-1],
            'over maximum' => [100001],
            'decimal string' => ['1.1'],
            'fraction' => ['0.5'],
            'scientific notation' => ['1e3'],
            'formatted decimal' => ['100.00'],
            'leading zero' => ['01'],
            'float' => [1.0],
        ];
    }

    public function test_record_requires_a_complete_self_consistent_snapshot(): void
    {
        $attributes = PaymentSnapshot::create(
            $this->profile(),
            self::RECIPIENT,
            125,
            '2026-09-06T12:00:00+09:00'
        )->databaseAttributes();
        $attributes['atomic_amount'] = '125000000';

        $this->expectException(InvalidPaymentSnapshotException::class);
        PaymentSnapshot::fromRecord($attributes);
    }

    public function test_legacy_record_without_snapshot_fails_closed(): void
    {
        $this->expectException(InvalidPaymentSnapshotException::class);

        PaymentSnapshot::fromRecord([
            'network' => null,
            'expires_at' => null,
        ]);
    }

    private function profile(): NetworkProfile
    {
        return new NetworkProfile(
            id: 'kairos',
            version: 1,
            chainId: 1001,
            chainName: 'Kaia Kairos Testnet',
            rpcUrl: 'https://rpc.example.test',
            secondaryRpcUrl: null,
            explorerUrl: 'https://kairos.kaiascan.io',
            currencyName: 'KAIA',
            currencySymbol: 'KAIA',
            currencyDecimals: 18,
            jpycContract: self::TOKEN,
            jpycSymbol: 'JPYC',
            jpycDecimals: 18,
            testnet: true,
            paymentExecutionEnabled: true,
            feeDelegationExecutionEnabled: true,
        );
    }
}
