<?php

namespace App\Blockchain;

use App\Services\Payments\PaymentVerificationException;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

final readonly class KaiaFinalityPolicy
{
    public const MODEL = 'kaia-ibft-immediate-finality';

    public function additionalConfirmationBlocks(string $network): int
    {
        $this->assertSupportedNetwork($network);

        return 0;
    }

    public function assertPaymentWindow(
        string $network,
        CarbonImmutable $blockTimestamp,
        DateTimeInterface|string $createdAt,
        CarbonImmutable $expiresAt
    ): void {
        $this->assertSupportedNetwork($network);

        try {
            $created = $createdAt instanceof DateTimeInterface
                ? CarbonImmutable::instance($createdAt)
                : CarbonImmutable::parse($createdAt, date_default_timezone_get());
        } catch (Throwable $exception) {
            throw new PaymentVerificationException(
                PaymentVerificationException::PAYMENT_SNAPSHOT_UNAVAILABLE,
                previous: $exception
            );
        }

        if ($blockTimestamp->lt($created) || $blockTimestamp->gt($expiresAt)) {
            throw new PaymentVerificationException(
                PaymentVerificationException::PAYMENT_EXPIRED
            );
        }
    }

    private function assertSupportedNetwork(string $network): void
    {
        if (! in_array($network, ['kairos', 'kaia-mainnet'], true)) {
            throw new PaymentVerificationException(
                PaymentVerificationException::CONFIRMATION_EVIDENCE_UNAVAILABLE
            );
        }
    }
}
