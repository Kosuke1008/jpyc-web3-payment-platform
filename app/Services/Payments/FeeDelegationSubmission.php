<?php

namespace App\Services\Payments;

final readonly class FeeDelegationSubmission
{
    public function __construct(
        public string $transactionHash,
        public int $providerHttpStatus
    ) {}
}
