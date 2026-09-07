<?php

namespace App\Services\Payments;

final readonly class ManagedKairosTestPreparation
{
    public function __construct(
        public int $paymentId,
        public string $senderAddress,
        public string $recipientAddress,
        public string $displayAmount,
        public int $chainId,
        public string $senderTxHash,
        public string $requestFingerprint
    ) {}
}
