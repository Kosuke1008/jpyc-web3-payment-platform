<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class PaymentSponsorshipService
{
    private const ATTEMPT_CACHE_PREFIX = 'livt:fee-sponsorship:payment:';

    public function __construct(
        private readonly FeeDelegatedTransactionInspector $inspector,
        private readonly FeeDelegationGateway $gateway
    ) {}

    public function sponsor(
        int|string $paymentId,
        string $senderSignedTransaction
    ): string {
        $this->assertKairosEnabled();

        $payment = Payment::with('store.wallet')->find($paymentId);
        $expiresAt = $this->assertPaymentEligible($payment);

        $tokenContract = $this->configuredTokenContract();
        $recipient = $payment->store?->wallet?->address;

        if (! is_string($recipient)
            || preg_match('/\A0x[0-9a-fA-F]{40}\z/', $recipient) !== 1) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::CONFIGURATION_ERROR
            );
        }

        $transfer = $this->inspector->inspect($senderSignedTransaction);

        if ($transfer->chainId !== 1001
            || strcasecmp($transfer->tokenContract, $tokenContract) !== 0
            || strcasecmp($transfer->recipient, $recipient) !== 0
            || bccomp(
                $transfer->atomicAmount,
                $this->expectedAtomicAmount($payment->amount),
                0
            ) !== 0) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::INVALID_TRANSACTION
            );
        }

        $fingerprint = hash('sha256', strtolower($senderSignedTransaction));
        $cachedHash = $this->reserveAttempt(
            $payment->id,
            $fingerprint,
            $expiresAt->copy()->addDay()
        );

        if ($cachedHash !== null) {
            return $cachedHash;
        }

        // No database transaction or row lock is held while the fee payer is
        // contacted. Payment finalization remains the existing confirm flow's
        // responsibility.
        try {
            $transactionHash = $this->gateway->sponsor(
                $senderSignedTransaction
            );
        } catch (PaymentSponsorshipException $exception) {
            if (in_array($exception->reason, [
                PaymentSponsorshipException::CONFIGURATION_ERROR,
                PaymentSponsorshipException::PROVIDER_REJECTED,
            ], true)) {
                $this->releaseRejectedAttempt(
                    $payment->id,
                    $fingerprint
                );
            }

            throw $exception;
        }

        $this->completeAttempt(
            $payment->id,
            $fingerprint,
            $transactionHash,
            $expiresAt->copy()->addDay()
        );

        return $transactionHash;
    }

    private function assertKairosEnabled(): void
    {
        if (config('services.fee_delegation.enabled') !== true) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::DISABLED
            );
        }

        if (config('services.web3.network') !== 'kairos'
            || (string) config('services.web3.chain_id') !== '1001') {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::CONFIGURATION_ERROR
            );
        }
    }

    private function assertPaymentEligible(?Payment $payment): Carbon
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

        if ($payment->expires_at === null) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::CONFIGURATION_ERROR
            );
        }

        try {
            $expiresAt = Carbon::parse($payment->expires_at);
        } catch (Throwable $exception) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::CONFIGURATION_ERROR,
                previous: $exception
            );
        }

        if (now()->gt($expiresAt)) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::PAYMENT_EXPIRED
            );
        }

        return $expiresAt;
    }

    private function configuredTokenContract(): string
    {
        $contract = config('services.web3.erc20_contract_address');

        if (! is_string($contract)
            || preg_match('/\A0x[0-9a-fA-F]{40}\z/', $contract) !== 1) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::CONFIGURATION_ERROR
            );
        }

        return strtolower($contract);
    }

    private function expectedAtomicAmount(mixed $amount): string
    {
        $decimals = config('services.web3.token_decimals');
        $amount = (string) $amount;

        if ((string) $decimals !== '18'
            || preg_match('/\A[0-9]+\z/', $amount) !== 1) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::CONFIGURATION_ERROR
            );
        }

        return bcmul($amount, bcpow('10', '18', 0), 0);
    }

    private function reserveAttempt(
        int $paymentId,
        string $fingerprint,
        Carbon $expiresAt
    ): ?string {
        return $this->withAttemptLock(
            $paymentId,
            PaymentSponsorshipException::PROVIDER_UNAVAILABLE,
            function (Repository $cache) use (
                $paymentId,
                $fingerprint,
                $expiresAt
            ): ?string {
                $key = $this->attemptKey($paymentId);
                $attempt = $cache->get($key);

                if ($attempt === null) {
                    $stored = $cache->put($key, [
                        'fingerprint' => $fingerprint,
                        'state' => 'reserved',
                    ], $expiresAt);

                    if ($stored !== true) {
                        throw new RuntimeException(
                            'Fee sponsorship reservation was not stored.'
                        );
                    }

                    return null;
                }

                if (! is_array($attempt)
                    || ! is_string($attempt['fingerprint'] ?? null)
                    || ! is_string($attempt['state'] ?? null)) {
                    throw new PaymentSponsorshipException(
                        PaymentSponsorshipException::PROVIDER_STATUS_UNKNOWN
                    );
                }

                if (! hash_equals($attempt['fingerprint'], $fingerprint)) {
                    throw new PaymentSponsorshipException(
                        PaymentSponsorshipException::SPONSORSHIP_CONFLICT
                    );
                }

                if ($attempt['state'] === 'succeeded'
                    && is_string($attempt['transaction_hash'] ?? null)
                    && preg_match(
                        '/\A0x[0-9a-f]{64}\z/',
                        $attempt['transaction_hash']
                    ) === 1) {
                    return $attempt['transaction_hash'];
                }

                throw new PaymentSponsorshipException(
                    PaymentSponsorshipException::PROVIDER_STATUS_UNKNOWN
                );
            }
        );
    }

    private function completeAttempt(
        int $paymentId,
        string $fingerprint,
        string $transactionHash,
        Carbon $expiresAt
    ): void {
        $this->withAttemptLock(
            $paymentId,
            PaymentSponsorshipException::PROVIDER_STATUS_UNKNOWN,
            function (Repository $cache) use (
                $paymentId,
                $fingerprint,
                $transactionHash,
                $expiresAt
            ): void {
                $key = $this->attemptKey($paymentId);
                $attempt = $cache->get($key);

                if (! is_array($attempt)
                    || ! is_string($attempt['fingerprint'] ?? null)
                    || ! hash_equals(
                        $attempt['fingerprint'],
                        $fingerprint
                    )) {
                    throw new PaymentSponsorshipException(
                        PaymentSponsorshipException::PROVIDER_STATUS_UNKNOWN
                    );
                }

                $stored = $cache->put($key, [
                    'fingerprint' => $fingerprint,
                    'state' => 'succeeded',
                    'transaction_hash' => $transactionHash,
                ], $expiresAt);

                if ($stored !== true) {
                    throw new RuntimeException(
                        'Fee sponsorship result was not stored.'
                    );
                }
            }
        );
    }

    private function releaseRejectedAttempt(
        int $paymentId,
        string $fingerprint
    ): void {
        try {
            $this->withAttemptLock(
                $paymentId,
                PaymentSponsorshipException::PROVIDER_UNAVAILABLE,
                function (Repository $cache) use (
                    $paymentId,
                    $fingerprint
                ): void {
                    $key = $this->attemptKey($paymentId);
                    $attempt = $cache->get($key);

                    if (is_array($attempt)
                        && is_string($attempt['fingerprint'] ?? null)
                        && hash_equals(
                            $attempt['fingerprint'],
                            $fingerprint
                        )) {
                        if ($cache->forget($key) !== true) {
                            throw new RuntimeException(
                                'Fee sponsorship reservation was not removed.'
                            );
                        }
                    }
                }
            );
        } catch (PaymentSponsorshipException) {
            // Keeping the reservation is safer than allowing a second raw
            // transaction when cache cleanup is unavailable.
        }
    }

    private function withAttemptLock(
        int $paymentId,
        string $failureReason,
        callable $operation
    ): mixed {
        try {
            $cache = $this->attemptCache();

            return $cache->getStore()
                ->lock($this->attemptLockKey($paymentId), 5)
                ->block(2, fn () => $operation($cache));
        } catch (PaymentSponsorshipException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PaymentSponsorshipException(
                $failureReason,
                previous: $exception
            );
        }
    }

    private function attemptCache(): Repository
    {
        $store = config('services.fee_delegation.cache_store');

        if (! is_string($store)
            || preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $store) !== 1) {
            throw new RuntimeException(
                'Fee sponsorship cache store is invalid.'
            );
        }

        return Cache::store($store);
    }

    private function attemptKey(int $paymentId): string
    {
        return self::ATTEMPT_CACHE_PREFIX.$paymentId;
    }

    private function attemptLockKey(int $paymentId): string
    {
        return $this->attemptKey($paymentId).':lock';
    }
}
