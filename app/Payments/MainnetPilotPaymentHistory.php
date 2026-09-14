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

        if ($payments->contains(
            fn (Payment $payment): bool => ! $this->isExpiredUnused(
                $payment,
                $identities,
                $profile
            )
        )) {
            throw new RuntimeException('expired_pilot_history_invalid');
        }
        if (PaymentFeeDelegationAttempt::query()
            ->whereIn('payment_id', $payments->pluck('id'))
            ->exists()) {
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
        if ($history->count() !== $payments->count() - 1
            || $history->contains(
                fn (Payment $payment): bool => ! $this->isExpiredUnused(
                    $payment,
                    $identities,
                    $profile
                )
            )
            || PaymentFeeDelegationAttempt::query()
                ->whereIn('payment_id', $history->pluck('id'))
                ->exists()) {
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
        } catch (Throwable) {
            return false;
        }

        return $payment->store_id === $identities['store']->id
            && $payment->staff_id === $identities['staff']->id
            && (string) $payment->amount === '1'
            && $payment->status === 'pending'
            && $snapshot->expiresAt->lt(now())
            && $snapshot->network === 'kaia-mainnet'
            && $snapshot->networkProfileVersion === $profile->version
            && $snapshot->chainId === 8217
            && $snapshot->tokenContract === strtolower($profile->jpycContract)
            && $snapshot->tokenSymbol === 'JPYC'
            && $snapshot->tokenDecimals === 18
            && $snapshot->recipientAddress === $identities['merchant']
            && $snapshot->displayAmount === '1'
            && $snapshot->atomicAmount === '1000000000000000000'
            && collect(self::EVIDENCE_FIELDS)->every(
                fn (string $field): bool => $payment->getRawOriginal($field) === null
            );
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
