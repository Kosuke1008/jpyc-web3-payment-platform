<?php

namespace App\Services\Payments;

interface FeeDelegationGateway
{
    public function provider(): string;

    public function assertConfigured(): void;

    public function sponsor(
        string $senderSignedTransaction,
        ?int $paymentId = null,
        ?string $expiresAt = null
    ): FeeDelegationSubmission;
}
