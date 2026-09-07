<?php

namespace App\Services\Payments;

use App\Blockchain\NetworkProfile;
use App\Blockchain\NetworkProfileRegistry;
use App\Models\Payment;
use App\Models\PaymentFeeDelegationAttempt;
use App\Payments\InvalidPaymentSnapshotException;
use App\Payments\PaymentSnapshot;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentSponsorshipService
{
    public function __construct(
        private readonly FeeDelegatedTransactionInspector $inspector,
        private readonly FeeDelegationGateway $gateway,
        private readonly NetworkProfileRegistry $networks,
        private readonly KaiaSenderTransactionHash $senderTransactionHash,
        private readonly ManagedKairosLiveTestPolicy $managedLiveTestPolicy
    ) {}

    public function sponsor(
        int|string $paymentId,
        string $senderSignedTransaction,
        int $requesterUserId
    ): string {
        $payment = Payment::find($paymentId);
        $snapshot = $this->assertPaymentEligible($payment);
        $profile = $this->networks->get($snapshot->network);
        $this->assertFeeDelegationEnabled($profile, $snapshot);

        $transfer = $this->inspector->inspect($senderSignedTransaction);

        if ($transfer->chainId !== $snapshot->chainId
            || strcasecmp($transfer->tokenContract, $snapshot->tokenContract) !== 0
            || strcasecmp($transfer->recipient, $snapshot->recipientAddress) !== 0
            || bccomp($transfer->atomicAmount, $snapshot->atomicAmount, 0) !== 0) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::INVALID_TRANSACTION
            );
        }

        // Future per-user/store rate limits and KAIA/daily budgets belong at
        // this boundary, after exact Payment policy validation and before any
        // provider can sign or broadcast.
        $this->gateway->assertConfigured();
        $provider = $this->gateway->provider();
        $this->managedLiveTestPolicy->assertSubmissionAllowed(
            $snapshot,
            (int) $payment->id,
            $provider
        );
        $fingerprint = hash('sha256', strtolower($senderSignedTransaction));
        $senderTxHash = $this->senderTransactionHash->fromSenderSignedRlp(
            $senderSignedTransaction
        );

        [$attempt, $knownHash] = $this->reserveAttempt(
            $payment,
            $snapshot,
            $transfer,
            $provider,
            $fingerprint,
            $senderTxHash,
            $requesterUserId
        );

        if ($knownHash !== null) {
            return $knownHash;
        }

        try {
            $submission = $this->gateway->sponsor($senderSignedTransaction);
        } catch (PaymentSponsorshipException $exception) {
            $state = match ($exception->reason) {
                PaymentSponsorshipException::PROVIDER_REJECTED => FeeDelegationAttemptState::REJECTED,
                PaymentSponsorshipException::PROVIDER_REVERTED => FeeDelegationAttemptState::REVERTED,
                default => FeeDelegationAttemptState::UNKNOWN_SUBMISSION,
            };
            $this->transitionAttempt($attempt->id, $state, [
                'provider_http_status' => $exception->upstreamStatus,
                'diagnostic_code' => match ($state) {
                    FeeDelegationAttemptState::REJECTED => 'provider_rejected',
                    FeeDelegationAttemptState::REVERTED => 'transaction_reverted',
                    default => 'provider_status_unknown',
                },
                ...(in_array($state, [
                    FeeDelegationAttemptState::REJECTED,
                    FeeDelegationAttemptState::REVERTED,
                ], true)
                    ? ['resolved_at' => now()]
                    : []),
            ]);

            throw $exception;
        }

        $this->transitionAttempt(
            $attempt->id,
            FeeDelegationAttemptState::SUBMITTED,
            [
                'tx_hash' => $submission->transactionHash,
                'provider_http_status' => $submission->providerHttpStatus,
                'diagnostic_code' => null,
                'submitted_at' => now(),
            ]
        );

        return $submission->transactionHash;
    }

    /** @return array{PaymentFeeDelegationAttempt, ?string} */
    private function reserveAttempt(
        Payment $payment,
        PaymentSnapshot $snapshot,
        SponsoredTransfer $transfer,
        string $provider,
        string $fingerprint,
        string $senderTxHash,
        int $requesterUserId
    ): array {
        try {
            return DB::transaction(function () use (
                $payment,
                $snapshot,
                $transfer,
                $provider,
                $fingerprint,
                $senderTxHash,
                $requesterUserId
            ): array {
                $lockedPayment = Payment::query()
                    ->whereKey($payment->id)
                    ->lockForUpdate()
                    ->first();
                $lockedSnapshot = $this->assertPaymentEligible($lockedPayment);

                if (! $snapshot->hasSameSemantics($lockedSnapshot)) {
                    throw new PaymentSponsorshipException(
                        PaymentSponsorshipException::CONFIGURATION_ERROR
                    );
                }

                $existing = PaymentFeeDelegationAttempt::query()
                    ->where('payment_id', $payment->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $this->existingAttempt(
                        $existing,
                        $provider,
                        $fingerprint,
                        $senderTxHash
                    );
                }

                $attempt = PaymentFeeDelegationAttempt::create([
                    'payment_id' => $payment->id,
                    'requester_user_id' => $requesterUserId,
                    'network' => $snapshot->network,
                    'chain_id' => $snapshot->chainId,
                    'provider' => $provider,
                    'sender_address' => strtolower($transfer->sender),
                    'sender_tx_hash' => strtolower($senderTxHash),
                    'request_fingerprint' => $fingerprint,
                    'sender_nonce' => $transfer->nonce,
                    'state' => FeeDelegationAttemptState::RESERVED,
                ]);
                $attempt->transitionTo(FeeDelegationAttemptState::VALIDATED, [
                    'validated_at' => now(),
                ]);
                $attempt->transitionTo(FeeDelegationAttemptState::SUBMITTING, [
                    'submitting_at' => now(),
                ]);

                return [$attempt, null];
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::SPONSORSHIP_CONFLICT,
                previous: $exception
            );
        }
    }

    /** @return array{PaymentFeeDelegationAttempt, ?string} */
    private function existingAttempt(
        PaymentFeeDelegationAttempt $attempt,
        string $provider,
        string $fingerprint,
        string $senderTxHash
    ): array {
        if ($attempt->provider !== $provider
            || ! hash_equals($attempt->request_fingerprint, $fingerprint)
            || ! hash_equals($attempt->sender_tx_hash, strtolower($senderTxHash))) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::SPONSORSHIP_CONFLICT
            );
        }

        if (in_array($attempt->state, [
            FeeDelegationAttemptState::SUBMITTED,
            FeeDelegationAttemptState::RECEIPT_OBSERVED,
            FeeDelegationAttemptState::CONFIRMED,
        ], true)
            && is_string($attempt->tx_hash)
            && preg_match('/\A0x[0-9a-f]{64}\z/', $attempt->tx_hash) === 1) {
            return [$attempt, $attempt->tx_hash];
        }

        if (in_array($attempt->state, [
            FeeDelegationAttemptState::REJECTED,
            FeeDelegationAttemptState::REVERTED,
        ], true)) {
            throw new PaymentSponsorshipException(
                $attempt->state === FeeDelegationAttemptState::REVERTED
                    ? PaymentSponsorshipException::PROVIDER_REVERTED
                    : PaymentSponsorshipException::PROVIDER_REJECTED,
                upstreamStatus: $attempt->provider_http_status
            );
        }

        throw new PaymentSponsorshipException(
            PaymentSponsorshipException::PROVIDER_STATUS_UNKNOWN,
            upstreamStatus: $attempt->provider_http_status
        );
    }

    private function transitionAttempt(
        int $attemptId,
        string $state,
        array $attributes
    ): void {
        DB::transaction(function () use ($attemptId, $state, $attributes): void {
            $attempt = PaymentFeeDelegationAttempt::query()
                ->whereKey($attemptId)
                ->lockForUpdate()
                ->firstOrFail();
            $attempt->transitionTo($state, $attributes);
        }, 3);
    }

    private function assertFeeDelegationEnabled(
        NetworkProfile $profile,
        PaymentSnapshot $snapshot
    ): void {
        if (config('services.fee_delegation.enabled') !== true) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::DISABLED
            );
        }

        try {
            $active = $this->networks->active();

            if ($active->id !== $snapshot->network
                || $profile->chainId !== $snapshot->chainId) {
                throw new RuntimeException(
                    'Fee payer cannot access the snapshotted network.'
                );
            }

            $this->networks->assertFeeDelegationExecutionAllowed($profile);
        } catch (RuntimeException $exception) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::CONFIGURATION_ERROR,
                previous: $exception
            );
        }
    }

    private function assertPaymentEligible(?Payment $payment): PaymentSnapshot
    {
        if ($payment === null) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::PAYMENT_NOT_FOUND
            );
        }

        if ($payment->status === 'confirmed') {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::PAYMENT_ALREADY_CONFIRMED
            );
        }

        if ($payment->status !== 'pending') {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::PAYMENT_NOT_PENDING
            );
        }

        try {
            $snapshot = PaymentSnapshot::fromRecord($payment);
        } catch (InvalidPaymentSnapshotException $exception) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::CONFIGURATION_ERROR,
                previous: $exception
            );
        }

        if (now()->gt($snapshot->expiresAt)) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::PAYMENT_EXPIRED
            );
        }

        return $snapshot;
    }
}
