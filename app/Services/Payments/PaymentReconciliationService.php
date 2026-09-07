<?php

namespace App\Services\Payments;

use App\Blockchain\NetworkExecutionDisabledException;
use App\Models\Payment;
use App\Payments\InvalidPaymentSnapshotException;
use App\Payments\PaymentConfirmationEvidence;
use App\Payments\PaymentSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PaymentReconciliationService
{
    public const VERIFIED = 'verified';

    public const ANOMALY = 'anomaly';

    public const TRANSIENT_FAILURE = 'transient_failure';

    public function __construct(
        private readonly OnChainPaymentEvidenceVerifier $evidenceVerifier
    ) {}

    public function reconcile(Payment|int|string $payment): string
    {
        $payment = $payment instanceof Payment
            ? $payment->fresh()
            : Payment::find($payment);

        if ($payment === null || $payment->status !== 'confirmed') {
            return $this->record(
                $payment,
                self::ANOMALY,
                'payment_not_confirmed'
            );
        }

        try {
            $snapshot = PaymentSnapshot::fromRecord($payment);
            $storedEvidence = PaymentConfirmationEvidence::fromRecord($payment);
        } catch (InvalidPaymentSnapshotException) {
            return $this->record(
                $payment,
                self::ANOMALY,
                PaymentVerificationException::PAYMENT_SNAPSHOT_UNAVAILABLE
            );
        } catch (Throwable) {
            return $this->record(
                $payment,
                self::ANOMALY,
                PaymentVerificationException::CONFIRMATION_EVIDENCE_UNAVAILABLE
            );
        }

        try {
            $currentEvidence = $this->evidenceVerifier->verify(
                $snapshot,
                $storedEvidence->transactionHash,
                $payment->created_at
            );
        } catch (PaymentVerificationException $exception) {
            $transient = in_array($exception->reason, [
                PaymentVerificationException::RPC_TRANSPORT_FAILURE,
                PaymentVerificationException::JSON_RPC_PROVIDER_ERROR,
            ], true);

            return $this->record(
                $payment,
                $transient ? self::TRANSIENT_FAILURE : self::ANOMALY,
                $exception->reason
            );
        } catch (NetworkExecutionDisabledException) {
            return $this->record(
                $payment,
                self::TRANSIENT_FAILURE,
                'network_execution_disabled'
            );
        } catch (Throwable) {
            return $this->record(
                $payment,
                self::ANOMALY,
                PaymentVerificationException::CONFIRMATION_EVIDENCE_UNAVAILABLE
            );
        }

        if (! $storedEvidence->matches($currentEvidence)) {
            return $this->record(
                $payment,
                self::ANOMALY,
                'confirmation_evidence_mismatch'
            );
        }

        return $this->record($payment, self::VERIFIED, null);
    }

    private function record(
        ?Payment $payment,
        string $status,
        ?string $errorCode
    ): string {
        if ($payment !== null) {
            DB::table('payments')
                ->where('id', $payment->id)
                ->where('status', 'confirmed')
                ->update([
                    'reconciliation_status' => $status,
                    'reconciliation_error_code' => $errorCode,
                    'reconciled_at' => now(),
                ]);

            if ($status !== self::VERIFIED) {
                Log::warning('Payment reconciliation requires attention.', [
                    'payment_id' => $payment->id,
                    'reconciliation_status' => $status,
                    'diagnostic_code' => $errorCode,
                ]);
            }
        }

        return $status;
    }
}
