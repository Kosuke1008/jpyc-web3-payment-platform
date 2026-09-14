<?php

namespace App\Services\Payments;

final class ConfiguredFeeDelegationGateway implements FeeDelegationGateway
{
    public function __construct(
        private readonly SelfHostedFeeDelegationGateway $selfHosted,
        private readonly KaiaManagedFeeDelegationGateway $managed
    ) {}

    public function provider(): string
    {
        return $this->selected()->provider();
    }

    public function assertConfigured(): void
    {
        $this->selected()->assertConfigured();
    }

    public function sponsor(
        string $senderSignedTransaction,
        ?int $paymentId = null,
        ?string $expiresAt = null
    ): FeeDelegationSubmission {
        return $this->selected()->sponsor($senderSignedTransaction, $paymentId, $expiresAt);
    }

    private function selected(): FeeDelegationGateway
    {
        return match (config('services.fee_delegation.mode')) {
            'self-hosted' => $this->selfHosted,
            'kaia-managed' => $this->managed,
            default => throw new PaymentSponsorshipException(
                PaymentSponsorshipException::CONFIGURATION_ERROR
            ),
        };
    }
}
