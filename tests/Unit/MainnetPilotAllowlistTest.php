<?php

namespace Tests\Unit;

use App\Payments\PaymentSnapshot;
use App\Services\Payments\MainnetPilotAllowlist;
use App\Services\Payments\PaymentSponsorshipException;
use App\Services\Payments\SponsoredTransfer;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MainnetPilotAllowlistTest extends TestCase
{
    private const MERCHANT = '0x1111111111111111111111111111111111111111';

    private const FEE_PAYER = '0x2222222222222222222222222222222222222222';

    private const SENDER = '0x3333333333333333333333333333333333333333';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.fee_delegation.mainnet_staging' => [
            'merchant_address' => self::MERCHANT,
            'kairos_merchant_address' => '0x4444444444444444444444444444444444444444',
            'fee_payer_address' => self::FEE_PAYER,
            'kairos_fee_payer_address' => '0x5555555555555555555555555555555555555555',
            'approved_user_ids' => '7',
            'approved_sender_addresses' => self::SENDER,
            'allow_cross_environment_reuse' => false,
        ]]);
    }

    public function test_approved_user_sender_and_merchant_are_accepted(): void
    {
        app(MainnetPilotAllowlist::class)->assertAllowed(
            $this->snapshot(),
            $this->transfer(),
            7
        );

        $this->assertTrue(true);
    }

    #[DataProvider('rejectedCases')]
    public function test_unapproved_or_reused_identity_is_rejected(
        int $userId,
        string $sender,
        string $recipient,
        ?string $configurationKey = null,
        ?string $configurationValue = null
    ): void {
        if ($configurationKey !== null) {
            config([
                "services.fee_delegation.mainnet_staging.{$configurationKey}" => $configurationValue,
            ]);
        }

        $this->expectException(PaymentSponsorshipException::class);
        app(MainnetPilotAllowlist::class)->assertAllowed(
            $this->snapshot($recipient),
            $this->transfer($sender, $recipient),
            $userId
        );
    }

    public static function rejectedCases(): array
    {
        return [
            'user' => [8, self::SENDER, self::MERCHANT],
            'sender' => [7, '0x6666666666666666666666666666666666666666', self::MERCHANT],
            'merchant' => [7, self::SENDER, '0x7777777777777777777777777777777777777777'],
            'fee payer equals merchant' => [
                7,
                self::SENDER,
                self::MERCHANT,
                'fee_payer_address',
                self::MERCHANT,
            ],
            'Kairos merchant reused' => [
                7,
                self::SENDER,
                self::MERCHANT,
                'kairos_merchant_address',
                self::MERCHANT,
            ],
        ];
    }

    private function snapshot(string $recipient = self::MERCHANT): PaymentSnapshot
    {
        return new PaymentSnapshot(
            network: 'kaia-mainnet',
            networkProfileVersion: 1,
            chainId: 8217,
            tokenContract: '0xe7c3d8c9a439fede00d2600032d5db0be71c3c29',
            tokenSymbol: 'JPYC',
            tokenDecimals: 18,
            recipientAddress: strtolower($recipient),
            displayAmount: '1',
            atomicAmount: '1000000000000000000',
            expiresAt: CarbonImmutable::now()->addMinute()
        );
    }

    private function transfer(
        string $sender = self::SENDER,
        string $recipient = self::MERCHANT
    ): SponsoredTransfer {
        return new SponsoredTransfer(
            chainId: 8217,
            sender: $sender,
            tokenContract: '0xe7c3d8c9a439fede00d2600032d5db0be71c3c29',
            recipient: $recipient,
            atomicAmount: '1000000000000000000',
            gasLimit: '100000',
            gasPrice: '25000000000',
            nonce: '1'
        );
    }
}
