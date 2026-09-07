<?php

namespace App\Services\Payments;

use App\Payments\InvalidPaymentSnapshotException;
use App\Payments\PaymentConfirmationEvidence;
use App\Payments\PaymentSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class PaymentTransactionVerifier
{
    public function __construct(
        private readonly OnChainPaymentEvidenceVerifier $evidenceVerifier
    ) {}

    public function verifyAndConfirm(
        int|string $paymentId,
        string $transactionHash,
        int $userId
    ): void {
        $normalizedHash = $this->normalizeTransactionHash($transactionHash);
        $payment = DB::table('payments')->where('id', $paymentId)->first();

        if ($payment === null) {
            throw new PaymentVerificationException(
                PaymentVerificationException::PAYMENT_NOT_FOUND
            );
        }

        if ($payment->status === 'confirmed') {
            try {
                $evidence = PaymentConfirmationEvidence::fromRecord($payment);

                if ($evidence->transactionHash === $normalizedHash) {
                    return;
                }
            } catch (Throwable) {
                // A legacy or incomplete confirmed row is not safe to treat
                // as an idempotent confirmation.
            }

            throw new PaymentVerificationException(
                PaymentVerificationException::PAYMENT_ALREADY_CONFIRMED
            );
        }

        $this->assertPaymentEligible($payment, $normalizedHash);
        $snapshot = $this->paymentSnapshot($payment);
        $createdAt = $this->createdAt($payment);

        // RPC work is intentionally completed before a DB transaction starts.
        $evidence = $this->evidenceVerifier->verify(
            $snapshot,
            $normalizedHash,
            $createdAt
        );

        try {
            DB::transaction(function () use (
                $paymentId,
                $normalizedHash,
                $userId,
                $snapshot,
                $createdAt,
                $evidence
            ): void {
                $payment = DB::table('payments')
                    ->where('id', $paymentId)
                    ->lockForUpdate()
                    ->first();
                $lockedSnapshot = $this->paymentSnapshot($payment);

                $this->assertPaymentEligible(
                    $payment,
                    $normalizedHash,
                    $lockedSnapshot
                );

                if (! $snapshot->hasSameSemantics($lockedSnapshot)
                    || ! $createdAt->equalTo($this->createdAt($payment))) {
                    throw new PaymentVerificationException(
                        PaymentVerificationException::PAYMENT_SNAPSHOT_UNAVAILABLE
                    );
                }

                $updated = DB::table('payments')
                    ->where('id', $paymentId)
                    ->where('status', 'pending')
                    ->update(array_merge(
                        $evidence->databaseAttributes(),
                        [
                            'status' => 'confirmed',
                            'paid_at' => now(),
                            'user_id' => $userId,
                            'reconciliation_status' => 'pending',
                            'reconciliation_error_code' => null,
                            'reconciled_at' => null,
                        ]
                    ));

                if ($updated !== 1) {
                    throw new PaymentVerificationException(
                        PaymentVerificationException::PAYMENT_NOT_PENDING
                    );
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw new PaymentVerificationException(
                PaymentVerificationException::DUPLICATE_TRANSACTION_HASH
            );
        }
    }

    private function normalizeTransactionHash(string $transactionHash): string
    {
        if (preg_match('/\A0x[0-9a-fA-F]{64}\z/', $transactionHash) !== 1) {
            throw new PaymentVerificationException(
                PaymentVerificationException::INVALID_TRANSACTION_HASH
            );
        }

        return strtolower($transactionHash);
    }

    private function assertPaymentEligible(
        ?object $payment,
        string $transactionHash,
        ?PaymentSnapshot $snapshot = null
    ): void {
        if ($payment === null) {
            throw new PaymentVerificationException(
                PaymentVerificationException::PAYMENT_NOT_FOUND
            );
        }

        if ($payment->status === 'confirmed') {
            throw new PaymentVerificationException(
                PaymentVerificationException::PAYMENT_ALREADY_CONFIRMED
            );
        }

        if ($payment->status !== 'pending') {
            throw new PaymentVerificationException(
                PaymentVerificationException::PAYMENT_NOT_PENDING
            );
        }

        $snapshot ??= $this->paymentSnapshot($payment);

        if (DB::table('payments')
            ->where('chain_id', $snapshot->chainId)
            ->where('tx_hash', $transactionHash)
            ->where('id', '<>', $payment->id)
            ->exists()) {
            throw new PaymentVerificationException(
                PaymentVerificationException::DUPLICATE_TRANSACTION_HASH
            );
        }
    }

    private function paymentSnapshot(object $payment): PaymentSnapshot
    {
        try {
            return PaymentSnapshot::fromRecord($payment);
        } catch (InvalidPaymentSnapshotException $exception) {
            throw new PaymentVerificationException(
                PaymentVerificationException::PAYMENT_SNAPSHOT_UNAVAILABLE,
                previous: $exception
            );
        }
    }

    private function createdAt(object $payment): CarbonImmutable
    {
        try {
            if (! isset($payment->created_at) || ! is_string($payment->created_at)) {
                throw new RuntimeException('Missing Payment creation time.');
            }

            return CarbonImmutable::parse(
                $payment->created_at,
                date_default_timezone_get()
            );
        } catch (Throwable $exception) {
            throw new PaymentVerificationException(
                PaymentVerificationException::PAYMENT_SNAPSHOT_UNAVAILABLE,
                previous: $exception
            );
        }
    }
}
