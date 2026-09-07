<?php

namespace Tests\Unit;

use App\Blockchain\KaiaFinalityPolicy;
use App\Services\Payments\PaymentVerificationException;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class KaiaFinalityPolicyTest extends TestCase
{
    public function test_kaia_uses_canonical_block_observation_without_extra_blocks(): void
    {
        $policy = new KaiaFinalityPolicy;

        $this->assertSame(0, $policy->additionalConfirmationBlocks('kairos'));
        $this->assertSame(0, $policy->additionalConfirmationBlocks('kaia-mainnet'));
    }

    public function test_block_timestamp_must_be_inside_payment_window(): void
    {
        $policy = new KaiaFinalityPolicy;

        $policy->assertPaymentWindow(
            'kairos',
            CarbonImmutable::parse('2026-09-06T03:05:00Z'),
            '2026-09-06T03:00:00Z',
            CarbonImmutable::parse('2026-09-06T03:10:00Z')
        );

        $this->addToAssertionCount(1);
    }

    public function test_non_kaia_network_has_no_implicit_finality_policy(): void
    {
        $this->expectException(PaymentVerificationException::class);

        (new KaiaFinalityPolicy)->additionalConfirmationBlocks('sepolia');
    }
}
