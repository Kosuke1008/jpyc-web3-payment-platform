<?php

namespace Tests\Unit;

use App\Payments\PaymentConfirmationEvidence;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PaymentConfirmationEvidenceTest extends TestCase
{
    public function test_evidence_is_normalized_and_compares_without_verification_time(): void
    {
        $first = PaymentConfirmationEvidence::create(
            1001,
            '0x'.str_repeat('A', 64),
            16,
            '0x'.str_repeat('B', 64),
            1,
            '0x'.str_repeat('C', 40),
            0,
            '2026-09-06T03:00:00Z',
            '2026-09-06T03:01:00Z'
        );
        $laterVerification = PaymentConfirmationEvidence::create(
            1001,
            '0x'.str_repeat('a', 64),
            16,
            '0x'.str_repeat('b', 64),
            1,
            '0x'.str_repeat('c', 40),
            0,
            '2026-09-06T03:00:00Z',
            '2026-09-06T03:05:00Z'
        );

        $this->assertSame('0x'.str_repeat('a', 64), $first->transactionHash);
        $this->assertSame('0x'.str_repeat('c', 40), $first->payerAddress);
        $this->assertTrue($first->matches($laterVerification));
    }

    public function test_zero_address_payer_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        PaymentConfirmationEvidence::create(
            1001,
            '0x'.str_repeat('a', 64),
            16,
            '0x'.str_repeat('b', 64),
            1,
            '0x'.str_repeat('0', 40),
            0,
            '2026-09-06T03:00:00Z',
            '2026-09-06T03:01:00Z'
        );
    }
}
