<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\PaymentFeeDelegationAttempt;
use App\Payments\PaymentSnapshot;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class SelfHostedMainnetSponsorshipPolicy
{
    public function __construct(
        private readonly MainnetPilotAllowlist $allowlist
    ) {}

    /**
     * @template Result
     *
     * @param  Closure(): Result  $operation
     * @return Result
     */
    public function run(
        Payment $payment,
        PaymentSnapshot $snapshot,
        SponsoredTransfer $transfer,
        string $provider,
        int $requesterUserId,
        Closure $operation
    ): mixed {
        if ($provider !== 'self-hosted'
            || $snapshot->network !== 'kaia-mainnet') {
            return $operation();
        }

        $pilotPaymentId = config(
            'services.fee_delegation.mainnet_staging.pilot_payment_id'
        );
        if ((! is_int($pilotPaymentId) && ! is_string($pilotPaymentId))
            || preg_match('/\A[1-9][0-9]*\z/', (string) $pilotPaymentId) !== 1
            || (string) $payment->id !== (string) $pilotPaymentId) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::POLICY_REJECTED
            );
        }

        $this->allowlist->assertAllowed(
            $snapshot,
            $transfer,
            $requesterUserId
        );
        $limits = $this->validatedLimits($snapshot, $transfer);

        try {
            return Cache::lock(
                'payments:self-hosted-mainnet-policy',
                10
            )->block(5, function () use (
                $payment,
                $transfer,
                $requesterUserId,
                $limits,
                $operation
            ): mixed {
                $this->assertUsageAvailable(
                    $payment,
                    $transfer,
                    $requesterUserId,
                    $limits
                );

                // The attempt DB transaction commits while the shared lock is
                // held, so another worker observes it before checking limits.
                return $operation();
            });
        } catch (LockTimeoutException) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::RATE_LIMITED
            );
        }
    }

    public function assertRuntimeGates(
        PaymentSnapshot $snapshot,
        string $provider
    ): void {
        if ($provider !== 'self-hosted' || $snapshot->network !== 'kaia-mainnet') {
            return;
        }

        $configuration = config('services.fee_delegation.self_hosted_mainnet');
        if (! is_array($configuration)
            || config('blockchain.mainnet_activation_release_capable') !== true
            || ($configuration['enabled'] ?? null) !== true
            || ($configuration['kill_switch'] ?? null) !== false
            || config('blockchain.payments_mainnet_enabled') !== true
            || config('blockchain.mainnet_fee_delegation_enabled') !== true
            || config('blockchain.mainnet_broadcast_enabled') !== true) {
            throw new PaymentSponsorshipException(
                ($configuration['kill_switch'] ?? true) === true
                    ? PaymentSponsorshipException::KILL_SWITCH_ACTIVE
                    : PaymentSponsorshipException::POLICY_REJECTED
            );
        }
    }

    /** @return array<string, int|string> */
    private function validatedLimits(
        PaymentSnapshot $snapshot,
        SponsoredTransfer $transfer
    ): array {
        $configuration = config(
            'services.fee_delegation.self_hosted_mainnet'
        );

        if (! is_array($configuration)
            || config('blockchain.mainnet_activation_release_capable') !== true
            || ($configuration['enabled'] ?? null) !== true
            || ($configuration['kill_switch'] ?? null) !== false
            || config('blockchain.payments_mainnet_enabled') !== true
            || config('blockchain.mainnet_fee_delegation_enabled') !== true
            || config('blockchain.mainnet_broadcast_enabled') !== true) {
            throw new PaymentSponsorshipException(
                ($configuration['kill_switch'] ?? true) === true
                    ? PaymentSponsorshipException::KILL_SWITCH_ACTIVE
                    : PaymentSponsorshipException::POLICY_REJECTED
            );
        }

        $limits = [];
        foreach ([
            'max_payment_jpy',
            'max_gas',
            'max_gas_price_wei',
            'rate_window_seconds',
            'max_attempts_per_user',
            'max_attempts_per_store',
            'max_attempts_per_sender',
            'max_attempts_global',
            'daily_transaction_limit',
        ] as $key) {
            $value = $configuration[$key] ?? null;
            if ((! is_int($value) && ! is_string($value))
                || preg_match('/\A[1-9][0-9]*\z/', (string) $value) !== 1) {
                throw new PaymentSponsorshipException(
                    PaymentSponsorshipException::POLICY_REJECTED
                );
            }
            $limits[$key] = (string) $value;
        }

        foreach ([
            'max_payment_jpy',
            'max_attempts_per_user',
            'max_attempts_per_store',
            'max_attempts_per_sender',
            'max_attempts_global',
            'daily_transaction_limit',
        ] as $pilotOneLimit) {
            if ($limits[$pilotOneLimit] !== '1') {
                throw new PaymentSponsorshipException(
                    PaymentSponsorshipException::POLICY_REJECTED
                );
            }
        }

        $limits['daily_budget_wei'] = $this->kaiaToWei(
            $configuration['daily_kaia_budget'] ?? null
        );
        $minimumReserveWei = $this->kaiaToWei(
            $configuration['minimum_reserve_kaia'] ?? null
        );

        if (bccomp($minimumReserveWei, '0', 0) <= 0) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::POLICY_REJECTED
            );
        }

        if (bccomp(
            $snapshot->displayAmount,
            $limits['max_payment_jpy'],
            0
        ) > 0
            || bccomp($transfer->gasLimit, $limits['max_gas'], 0) > 0
            || bccomp(
                $transfer->gasPrice,
                $limits['max_gas_price_wei'],
                0
            ) > 0) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::POLICY_REJECTED
            );
        }

        return $limits;
    }

    /** @param array<string, int|string> $limits */
    private function assertUsageAvailable(
        Payment $payment,
        SponsoredTransfer $transfer,
        int $requesterUserId,
        array $limits
    ): void {
        $windowStart = now()->subSeconds((int) $limits['rate_window_seconds']);
        $base = PaymentFeeDelegationAttempt::query()
            ->where('network', 'kaia-mainnet')
            ->where('provider', 'self-hosted');
        $window = (clone $base)->where('created_at', '>=', $windowStart);

        $rateExceeded = (clone $window)
            ->where('requester_user_id', $requesterUserId)
            ->count() >= (int) $limits['max_attempts_per_user']
            || (clone $window)
                ->where('sender_address', strtolower($transfer->sender))
                ->count() >= (int) $limits['max_attempts_per_sender']
            || (clone $window)
                ->whereHas('payment', fn ($query) => $query->where(
                    'store_id',
                    $payment->store_id
                ))
                ->count() >= (int) $limits['max_attempts_per_store']
            || (clone $window)->count()
                >= (int) $limits['max_attempts_global'];

        if ($rateExceeded) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::RATE_LIMITED
            );
        }

        $todayCount = (clone $base)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
        if ($todayCount >= (int) $limits['daily_transaction_limit']) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::BUDGET_EXCEEDED
            );
        }

        $reservedPerPriorAttempt = bcmul(
            $limits['max_gas'],
            $limits['max_gas_price_wei'],
            0
        );
        $candidate = bcmul(
            $transfer->gasLimit,
            $transfer->gasPrice,
            0
        );
        $reserved = bcadd(
            bcmul((string) $todayCount, $reservedPerPriorAttempt, 0),
            $candidate,
            0
        );

        if (bccomp($reserved, $limits['daily_budget_wei'], 0) > 0) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::BUDGET_EXCEEDED
            );
        }
    }

    private function kaiaToWei(mixed $value): string
    {
        if (! is_string($value)
            || preg_match('/\A(0|[1-9][0-9]*)(\.[0-9]{1,18})?\z/', $value) !== 1) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::POLICY_REJECTED
            );
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return bcadd(
            bcmul($whole, bcpow('10', '18', 0), 0),
            str_pad($fraction, 18, '0'),
            0
        );
    }
}
