<?php

namespace App\Payments;

use App\Blockchain\NetworkProfile;
use App\Models\Payment;
use App\Models\PaymentFeeDelegationAttempt;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class MainnetPilotPaymentHistory
{
    private const EVIDENCE_FIELDS = [
        'tx_hash',
        'observed_chain_id',
        'confirmed_block_number',
        'confirmed_block_hash',
        'receipt_status',
        'payer_address',
        'transfer_log_index',
        'chain_confirmed_at',
        'verified_at',
        'paid_at',
        'user_id',
        'reconciliation_status',
        'reconciliation_error_code',
        'reconciled_at',
    ];

    /**
     * @param  Collection<int, Payment>  $payments
     * @param  array{store: mixed, staff: mixed, merchant: string}  $identities
     * @return array{latest: Payment, history_count: int}
     */
    public function assertReplaceable(
        Collection $payments,
        mixed $configuredId,
        array $identities,
        NetworkProfile $profile
    ): array {
        $payments = $payments->sortBy('id')->values();
        if ($payments->isEmpty()) {
            throw new RuntimeException('expired_pilot_history_required');
        }

        $latest = $payments->last();
        if (! $latest instanceof Payment) {
            throw new RuntimeException('expired_pilot_history_invalid');
        }
        if (in_array($configuredId, [null, ''], true)) {
            if ($payments->count() > 1) {
                throw new RuntimeException('latest_pilot_payment_id_required');
            }
        } elseif (MainnetPilotGuard::positiveId($configuredId) !== $latest->id) {
            throw new RuntimeException('pilot_payment_id_does_not_reference_latest_expired_payment');
        }

        $attempts = $this->attemptsFor($payments);
        $history = $payments->slice(0, -1)->values();
        if (! $this->isExpiredUnused($latest, $identities, $profile)
            || $history->contains(
                fn (Payment $payment): bool => ! $this->isExpiredUnused($payment, $identities, $profile)
                    && ! $this->isConfirmedHistoricalPayment($payment, $identities, $profile)
            )) {
            throw new RuntimeException('expired_pilot_history_invalid');
        }
        if (! $this->hasSafeUnusedAttempts($latest, $attempts)
            || $history->contains(
                fn (Payment $payment): bool => ! $this->hasSafeHistoricalAttempts(
                    $payment,
                    $attempts,
                    $identities,
                    $profile
                )
            )) {
            throw new RuntimeException('fee_delegation_attempt_exists');
        }
        if ($this->hasLegacyTransaction($payments)) {
            throw new RuntimeException('legacy_transaction_record_exists');
        }

        return ['latest' => $latest, 'history_count' => $payments->count()];
    }

    /** @param array{store: mixed, staff: mixed, merchant: string} $identities */
    public function supportsCurrent(
        Payment $current,
        array $identities,
        NetworkProfile $profile
    ): bool {
        $payments = Payment::query()->orderBy('id')->get();
        $latest = $payments->last();
        if (! $latest instanceof Payment || $latest->id !== $current->id) {
            return false;
        }

        $history = $payments->where('id', '<', $current->id)->values();
        $attempts = $this->attemptsFor($history);
        if ($history->count() !== $payments->count() - 1
            || $history->contains(
                fn (Payment $payment): bool => ! $this->isSafeHistoricalPayment(
                    $payment,
                    $attempts,
                    $identities,
                    $profile
                )
            )) {
            return false;
        }

        return ! $this->hasLegacyTransaction($history);
    }

    /** @param array{store: mixed, staff: mixed, merchant: string} $identities */
    private function isExpiredUnused(
        Payment $payment,
        array $identities,
        NetworkProfile $profile
    ): bool {
        try {
            $snapshot = PaymentSnapshot::fromRecord($payment);
            $maximum = MainnetPilotLimits::paymentMaximum();
        } catch (Throwable) {
            return false;
        }

        return $payment->store_id === $identities['store']->id
            && $payment->staff_id === $identities['staff']->id
            && bccomp((string) $payment->amount, $maximum, 0) <= 0
            && $payment->status === 'pending'
            && $snapshot->expiresAt->lt(now())
            && $snapshot->network === 'kaia-mainnet'
            && $snapshot->networkProfileVersion === $profile->version
            && $snapshot->chainId === 8217
            && $snapshot->tokenContract === strtolower($profile->jpycContract)
            && $snapshot->tokenSymbol === 'JPYC'
            && $snapshot->tokenDecimals === 18
            && $snapshot->recipientAddress === $identities['merchant']
            && $snapshot->displayAmount === (string) $payment->amount
            && $snapshot->atomicAmount === bcmul($snapshot->displayAmount, bcpow('10', '18', 0), 0)
            && collect(self::EVIDENCE_FIELDS)->every(
                fn (string $field): bool => $payment->getRawOriginal($field) === null
            );
    }

    /**
     * @param  Collection<int, PaymentFeeDelegationAttempt>  $attempts
     * @param  array{store: mixed, staff: mixed, user: mixed, merchant: string, sender: string}  $identities
     */
    private function isSafeHistoricalPayment(
        Payment $payment,
        Collection $attempts,
        array $identities,
        NetworkProfile $profile
    ): bool {
        if ($this->isExpiredUnused($payment, $identities, $profile)) {
            return $this->hasSafeUnusedAttempts($payment, $attempts);
        }

        return $this->isConfirmedHistoricalPayment($payment, $identities, $profile)
            && $this->hasSafeConfirmedAttempt($payment, $attempts, $identities);
    }

    /**
     * @param  Collection<int, PaymentFeeDelegationAttempt>  $attempts
     * @param  array{store: mixed, staff: mixed, user: mixed, merchant: string, sender: string}  $identities
     */
    private function hasSafeHistoricalAttempts(
        Payment $payment,
        Collection $attempts,
        array $identities,
        NetworkProfile $profile
    ): bool {
        return $this->isExpiredUnused($payment, $identities, $profile)
            ? $this->hasSafeUnusedAttempts($payment, $attempts)
            : $this->hasSafeConfirmedAttempt($payment, $attempts, $identities);
    }

    /** @return Collection<int, PaymentFeeDelegationAttempt> */
    private function attemptsFor(Collection $payments): Collection
    {
        if ($payments->isEmpty()) {
            return collect();
        }

        return PaymentFeeDelegationAttempt::query()
            ->whereIn('payment_id', $payments->pluck('id'))
            ->get();
    }

    /** @param Collection<int, PaymentFeeDelegationAttempt> $attempts */
    private function hasSafeUnusedAttempts(Payment $payment, Collection $attempts): bool
    {
        $paymentAttempts = $attempts
            ->where('payment_id', $payment->id)
            ->values();

        if ($paymentAttempts->isEmpty()) {
            return true;
        }

        if ($paymentAttempts->count() !== 1) {
            return false;
        }

        $attempt = $paymentAttempts->first();

        return $attempt instanceof PaymentFeeDelegationAttempt
            && $attempt->provider === 'self-hosted'
            && $attempt->state === 'rejected'
            && $attempt->provider_http_status === 400
            && $attempt->diagnostic_code === 'provider_rejected'
            && $attempt->broadcast_certainty === 'definitely_not_broadcast'
            && $attempt->tx_hash === null
            && $attempt->validated_at !== null
            && $attempt->submitting_at !== null
            && $attempt->submitted_at === null
            && $attempt->receipt_observed_at === null
            && $attempt->resolved_at !== null;
    }

    /** @param array{store: mixed, staff: mixed, user: mixed, merchant: string, sender: string} $identities */
    private function isConfirmedHistoricalPayment(
        Payment $payment,
        array $identities,
        NetworkProfile $profile
    ): bool {
        try {
            $snapshot = PaymentSnapshot::fromRecord($payment);
            $maximum = MainnetPilotLimits::paymentMaximum();
        } catch (Throwable) {
            return false;
        }

        return $payment->store_id === $identities['store']->id
            && $payment->staff_id === $identities['staff']->id
            && $payment->user_id === $identities['user']->id
            && bccomp((string) $payment->amount, $maximum, 0) <= 0
            && $payment->status === 'confirmed'
            && $this->isHash($payment->tx_hash)
            && $payment->observed_chain_id === 8217
            && is_int($payment->confirmed_block_number)
            && $payment->confirmed_block_number > 0
            && $this->isHash($payment->confirmed_block_hash)
            && $payment->receipt_status === 1
            && is_string($payment->payer_address)
            && hash_equals(strtolower($identities['sender']), strtolower($payment->payer_address))
            && is_int($payment->transfer_log_index)
            && $payment->transfer_log_index >= 0
            && $payment->chain_confirmed_at !== null
            && $payment->verified_at !== null
            && $payment->paid_at !== null
            && in_array($payment->reconciliation_status, [
                'pending',
                'verified',
            ], true)
            && $snapshot->network === 'kaia-mainnet'
            && $snapshot->networkProfileVersion === $profile->version
            && $snapshot->chainId === 8217
            && $snapshot->tokenContract === strtolower($profile->jpycContract)
            && $snapshot->tokenSymbol === 'JPYC'
            && $snapshot->tokenDecimals === 18
            && $snapshot->recipientAddress === $identities['merchant']
            && $snapshot->displayAmount === (string) $payment->amount
            && $snapshot->atomicAmount === bcmul($snapshot->displayAmount, bcpow('10', '18', 0), 0);
    }

    /**
     * @param  Collection<int, PaymentFeeDelegationAttempt>  $attempts
     * @param  array{user: mixed, sender: string}  $identities
     */
    private function hasSafeConfirmedAttempt(
        Payment $payment,
        Collection $attempts,
        array $identities
    ): bool {
        $paymentAttempts = $attempts
            ->where('payment_id', $payment->id)
            ->values();
        if ($paymentAttempts->count() !== 1) {
            return false;
        }

        $attempt = $paymentAttempts->first();
        if (! $attempt instanceof PaymentFeeDelegationAttempt
            || $attempt->requester_user_id !== $identities['user']->id
            || $attempt->network !== 'kaia-mainnet'
            || $attempt->chain_id !== 8217
            || $attempt->provider !== 'self-hosted'
            || ! is_string($attempt->sender_address)
            || ! hash_equals(strtolower($identities['sender']), strtolower($attempt->sender_address))
            || ! $this->isHash($attempt->sender_tx_hash)
            || ! $this->isHash($attempt->tx_hash)
            || ! hash_equals(strtolower($payment->tx_hash), strtolower($attempt->tx_hash))
            || ! is_string($attempt->request_fingerprint)
            || preg_match('/\A[0-9a-f]{64}\z/', $attempt->request_fingerprint) !== 1
            || preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/', (string) $attempt->sender_nonce) !== 1
            || $attempt->validated_at === null
            || $attempt->submitting_at === null
            || $attempt->submitted_at === null) {
            return false;
        }

        $modern = $attempt->state === 'confirmed'
            && $attempt->broadcast_certainty === 'submitted'
            && $attempt->receipt_observed_at !== null
            && $attempt->resolved_at !== null;

        // Payment 1 predates broadcast_certainty and attempt resolution. Its
        // immutable Payment evidence is complete, so accept it only as closed
        // history; it never becomes retryable or replacement-eligible.
        $legacyConfirmed = $payment->mainnet_authorized_at === null
            && $attempt->state === 'submitted'
            && $attempt->broadcast_certainty === null
            && $attempt->receipt_observed_at === null
            && $attempt->resolved_at === null;

        return $modern || $legacyConfirmed;
    }

    private function isHash(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A0x[0-9a-fA-F]{64}\z/', $value) === 1;
    }

    /** @param Collection<int, Payment> $payments */
    private function hasLegacyTransaction(Collection $payments): bool
    {
        return Schema::hasTable('transactions')
            && $payments->contains(
                fn (Payment $payment): bool => $payment->transaction()->exists()
            );
    }
}
