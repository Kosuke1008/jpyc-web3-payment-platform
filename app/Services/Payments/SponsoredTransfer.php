<?php

namespace App\Services\Payments;

final readonly class SponsoredTransfer
{
    public function __construct(
        public int $chainId,
        public string $sender,
        public string $tokenContract,
        public string $recipient,
        public string $atomicAmount,
        public string $gasLimit,
        public string $nonce
    ) {}
}
