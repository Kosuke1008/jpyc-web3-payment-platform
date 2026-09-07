<?php

namespace App\Services\Payments;

use App\Payments\PaymentSnapshot;

final class ManagedKairosLiveTestPolicy
{
    public const ENDPOINT = 'https://fee-delegation-kairos.kaia.io';

    public const MAXIMUM_FIRST_TEST_JPYC = 1;

    public function assertSubmissionAllowed(
        PaymentSnapshot $snapshot,
        int $paymentId,
        string $provider
    ): void {
        if ($provider !== 'kaia-managed') {
            return;
        }

        $maximum = $this->assertBaseConfiguration($paymentId);

        if ($snapshot->network !== 'kairos'
            || $snapshot->chainId !== 1001
            || bccomp(
                $snapshot->displayAmount,
                (string) $maximum,
                0
            ) > 0) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::CONFIGURATION_ERROR
            );
        }
    }

    public function assertBaseConfiguration(int $paymentId): int
    {
        $selectedPayment = config(
            'services.fee_delegation.kairos_managed_live_test_payment_id'
        );
        $maximum = config(
            'services.fee_delegation.kairos_managed_live_test_max_jpy'
        );
        $endpoint = config('services.fee_delegation.url');

        if (config('services.fee_delegation.kairos_managed_live_test_enabled') !== true
            || config('services.fee_delegation.mode') !== 'kaia-managed'
            || ! $this->positiveInteger($selectedPayment)
            || (int) $selectedPayment !== $paymentId
            || ! $this->positiveInteger($maximum)
            || (int) $maximum > self::MAXIMUM_FIRST_TEST_JPYC
            || ! is_string($endpoint)
            || rtrim($endpoint, '/') !== self::ENDPOINT
            || config('blockchain.payments_mainnet_enabled') !== false
            || config('blockchain.mainnet_fee_delegation_enabled') !== false
            || config('blockchain.mainnet_broadcast_enabled') !== false) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::CONFIGURATION_ERROR
            );
        }

        return (int) $maximum;
    }

    private function positiveInteger(mixed $value): bool
    {
        return (is_int($value) || is_string($value))
            && preg_match('/\A[1-9][0-9]*\z/', (string) $value) === 1
            && filter_var(
                $value,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            ) !== false;
    }
}
