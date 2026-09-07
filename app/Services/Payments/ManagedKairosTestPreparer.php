<?php

namespace App\Services\Payments;

use App\Blockchain\KairosManagedReadinessChecker;
use App\Blockchain\NetworkProfileRegistry;
use App\Models\Payment;
use App\Models\PaymentFeeDelegationAttempt;
use App\Payments\PaymentSnapshot;
use Throwable;

final class ManagedKairosTestPreparer
{
    public function __construct(
        private readonly NetworkProfileRegistry $networks,
        private readonly KairosManagedReadinessChecker $readiness,
        private readonly FeeDelegatedTransactionInspector $inspector,
        private readonly KaiaSenderTransactionHash $senderTransactionHash,
        private readonly ManagedKairosLiveTestPolicy $policy
    ) {}

    public function prepare(
        int $paymentId,
        string $senderSignedTransaction
    ): ManagedKairosTestPreparation {
        $payment = Payment::find($paymentId);

        if ($payment === null) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::PAYMENT_NOT_FOUND
            );
        }

        if ($payment->status !== 'pending') {
            throw new PaymentSponsorshipException(
                $payment->status === 'confirmed'
                    ? PaymentSponsorshipException::PAYMENT_ALREADY_CONFIRMED
                    : PaymentSponsorshipException::PAYMENT_NOT_PENDING
            );
        }

        try {
            $snapshot = PaymentSnapshot::fromRecord($payment);
            $active = $this->networks->active();
            $profile = $this->networks->get($snapshot->network);
            $this->networks->assertPaymentExecutionAllowed($profile);
            $this->networks->assertFeeDelegationExecutionAllowed($profile);
        } catch (Throwable $exception) {
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

        if ($active->id !== 'kairos'
            || $active->id !== $snapshot->network
            || $profile->chainId !== 1001
            || $snapshot->chainId !== 1001
            || config('services.fee_delegation.enabled') !== true) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::CONFIGURATION_ERROR
            );
        }

        $this->policy->assertSubmissionAllowed(
            $snapshot,
            $paymentId,
            'kaia-managed'
        );

        if (PaymentFeeDelegationAttempt::query()
            ->where('payment_id', $paymentId)
            ->exists()) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::SPONSORSHIP_CONFLICT
            );
        }

        if (! $this->readiness->check()->ready) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::CONFIGURATION_ERROR
            );
        }

        $transfer = $this->inspector->inspect($senderSignedTransaction);

        if ($transfer->chainId !== $snapshot->chainId
            || strcasecmp($transfer->tokenContract, $snapshot->tokenContract) !== 0
            || strcasecmp($transfer->recipient, $snapshot->recipientAddress) !== 0
            || bccomp($transfer->atomicAmount, $snapshot->atomicAmount, 0) !== 0) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::INVALID_TRANSACTION
            );
        }

        return new ManagedKairosTestPreparation(
            paymentId: $paymentId,
            senderAddress: strtolower($transfer->sender),
            recipientAddress: $snapshot->recipientAddress,
            displayAmount: $snapshot->displayAmount,
            chainId: $snapshot->chainId,
            senderTxHash: $this->senderTransactionHash->fromSenderSignedRlp(
                $senderSignedTransaction
            ),
            requestFingerprint: hash(
                'sha256',
                strtolower($senderSignedTransaction)
            )
        );
    }
}
