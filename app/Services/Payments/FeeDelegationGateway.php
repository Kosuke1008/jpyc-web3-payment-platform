<?php

namespace App\Services\Payments;

interface FeeDelegationGateway
{
    public function provider(): string;

    public function assertConfigured(): void;

    public function sponsor(
        string $senderSignedTransaction
    ): FeeDelegationSubmission;
}
