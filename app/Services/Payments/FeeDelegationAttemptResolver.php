<?php

namespace App\Services\Payments;

use App\Blockchain\NetworkProfileRegistry;
use App\Models\PaymentFeeDelegationAttempt;
use App\Payments\InvalidPaymentSnapshotException;
use App\Payments\PaymentSnapshot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class FeeDelegationAttemptResolver
{
    public const NOT_FOUND = 'not_found';

    public const SUBMITTED = 'submitted';

    public const CONFIRMED = 'confirmed';

    public const REVERTED = 'reverted';

    public const TRANSIENT_FAILURE = 'transient_failure';

    public const VERIFICATION_REJECTED = 'verification_rejected';

    private const MAX_RESPONSE_BYTES = 1_048_576;

    public function __construct(
        private readonly NetworkProfileRegistry $networks,
        private readonly PaymentTransactionVerifier $verifier
    ) {}

    public function resolve(PaymentFeeDelegationAttempt $attempt): string
    {
        $attempt->refresh();

        if ($attempt->state === FeeDelegationAttemptState::SUBMITTING) {
            $this->transition($attempt, FeeDelegationAttemptState::UNKNOWN_SUBMISSION, [
                'diagnostic_code' => 'interrupted_submission',
            ]);
        }

        if (! in_array($attempt->state, [
            FeeDelegationAttemptState::UNKNOWN_SUBMISSION,
            FeeDelegationAttemptState::SUBMITTED,
            FeeDelegationAttemptState::RECEIPT_OBSERVED,
        ], true)) {
            return $attempt->state;
        }

        try {
            $payment = $attempt->payment()->firstOrFail();
            $snapshot = PaymentSnapshot::fromRecord($payment);
            $profile = $this->networks->get($attempt->network);

            if ($snapshot->chainId !== $attempt->chain_id
                || $profile->chainId !== $attempt->chain_id) {
                throw new RuntimeException('Attempt network mismatch.');
            }

            $indexing = $this->rpc(
                $profile->rpcUrl,
                'kaia_isSenderTxHashIndexingEnabled',
                []
            );

            if ($indexing !== true) {
                return $this->checked(
                    $attempt,
                    self::TRANSIENT_FAILURE,
                    'sender_tx_indexing_unavailable'
                );
            }

            $transaction = $this->rpc(
                $profile->rpcUrl,
                'kaia_getTransactionBySenderTxHash',
                [$attempt->sender_tx_hash]
            );
            $receipt = $this->rpc(
                $profile->rpcUrl,
                'kaia_getTransactionReceiptBySenderTxHash',
                [$attempt->sender_tx_hash]
            );
        } catch (ConnectionException|InvalidPaymentSnapshotException|RuntimeException $exception) {
            return $this->checked(
                $attempt,
                self::TRANSIENT_FAILURE,
                'sender_tx_lookup_unavailable'
            );
        }

        if ($transaction === null && $receipt === null) {
            return $this->checked(
                $attempt,
                self::NOT_FOUND,
                'sender_tx_not_found'
            );
        }

        if (($transaction !== null && ! is_array($transaction))
            || ($receipt !== null && ! is_array($receipt))) {
            return $this->checked(
                $attempt,
                self::TRANSIENT_FAILURE,
                'sender_tx_lookup_malformed'
            );
        }

        $hash = $this->transactionHash($transaction, $receipt);

        if ($hash === null
            || ! $this->matchesAttempt($attempt, $snapshot, $transaction, $receipt, $hash)) {
            return $this->checked(
                $attempt,
                self::VERIFICATION_REJECTED,
                'sender_tx_mismatch'
            );
        }

        if ($receipt === null) {
            if ($attempt->state === FeeDelegationAttemptState::UNKNOWN_SUBMISSION) {
                $this->transition($attempt, FeeDelegationAttemptState::SUBMITTED, [
                    'tx_hash' => $hash,
                    'submitted_at' => now(),
                    'last_checked_at' => now(),
                    'diagnostic_code' => 'receipt_pending',
                ]);
            } else {
                $this->metadata($attempt, [
                    'tx_hash' => $hash,
                    'last_checked_at' => now(),
                    'diagnostic_code' => 'receipt_pending',
                ]);
            }

            return $this->resolutionResult($attempt, self::SUBMITTED);
        }

        $receiptStatus = $receipt['status'] ?? null;

        if (in_array($receiptStatus, [0, '0', '0x0'], true)) {
            $this->transition($attempt, FeeDelegationAttemptState::REVERTED, [
                'tx_hash' => $hash,
                'receipt_observed_at' => now(),
                'last_checked_at' => now(),
                'resolved_at' => now(),
                'diagnostic_code' => 'transaction_reverted',
            ]);

            return $this->resolutionResult($attempt, self::REVERTED);
        }

        if (! in_array($receiptStatus, [1, '1', '0x1'], true)) {
            return $this->checked(
                $attempt,
                self::TRANSIENT_FAILURE,
                'receipt_status_unknown'
            );
        }

        if ($attempt->state !== FeeDelegationAttemptState::RECEIPT_OBSERVED) {
            $this->transition($attempt, FeeDelegationAttemptState::RECEIPT_OBSERVED, [
                'tx_hash' => $hash,
                'receipt_observed_at' => now(),
                'last_checked_at' => now(),
                'diagnostic_code' => null,
            ]);
        }

        try {
            $this->verifier->verifyAndConfirm(
                $attempt->payment_id,
                $hash,
                $attempt->requester_user_id
            );
        } catch (PaymentVerificationException) {
            $this->metadata($attempt, [
                'last_checked_at' => now(),
                'diagnostic_code' => 'payment_verification_rejected',
            ]);

            return $this->resolutionResult(
                $attempt,
                self::VERIFICATION_REJECTED
            );
        }

        $this->transition($attempt, FeeDelegationAttemptState::CONFIRMED, [
            'resolved_at' => now(),
            'last_checked_at' => now(),
            'diagnostic_code' => null,
        ]);

        return $this->resolutionResult($attempt, self::CONFIRMED);
    }

    private function matchesAttempt(
        PaymentFeeDelegationAttempt $attempt,
        PaymentSnapshot $snapshot,
        ?array $transaction,
        ?array $receipt,
        string $hash
    ): bool {
        foreach ([$transaction, $receipt] as $record) {
            if ($record === null) {
                continue;
            }

            $recordHash = $record['hash'] ?? $record['transactionHash'] ?? null;
            $senderTxHash = $record['senderTxHash'] ?? null;

            if (($recordHash !== null && $this->hash($recordHash) !== $hash)
                || ($senderTxHash !== null
                    && $this->hash($senderTxHash) !== $attempt->sender_tx_hash)) {
                return false;
            }
        }

        if ($transaction === null) {
            return true;
        }

        $expectedInput = '0xa9059cbb'
            .str_repeat('0', 24)
            .substr(strtolower($snapshot->recipientAddress), 2)
            .str_pad(gmp_strval(gmp_init($snapshot->atomicAmount, 10), 16), 64, '0', STR_PAD_LEFT);
        $type = $transaction['type'] ?? null;
        $typeInt = $transaction['typeInt'] ?? null;

        return strtolower((string) ($transaction['from'] ?? '')) === $attempt->sender_address
            && strtolower((string) ($transaction['to'] ?? '')) === $snapshot->tokenContract
            && strtolower((string) ($transaction['input'] ?? '')) === $expectedInput
            && $this->quantity($transaction['value'] ?? null) === '0'
            && $this->quantity($transaction['nonce'] ?? null) === $attempt->sender_nonce
            && ($type === 'TxTypeFeeDelegatedSmartContractExecution'
                || $typeInt === 49
                || $typeInt === '0x31');
    }

    private function transactionHash(?array $transaction, ?array $receipt): ?string
    {
        $hashes = [];

        foreach ([$transaction, $receipt] as $record) {
            if ($record === null) {
                continue;
            }

            $value = $record['hash'] ?? $record['transactionHash'] ?? null;
            $normalized = $this->hash($value);

            if ($normalized === null) {
                return null;
            }

            $hashes[] = $normalized;
        }

        return count(array_unique($hashes)) === 1 ? $hashes[0] : null;
    }

    private function hash(mixed $value): ?string
    {
        return is_string($value)
            && preg_match('/\A0x[0-9a-fA-F]{64}\z/', $value) === 1
                ? strtolower($value)
                : null;
    }

    private function quantity(mixed $value): ?string
    {
        if (! is_string($value)
            || preg_match('/\A0x[0-9a-fA-F]+\z/', $value) !== 1) {
            return null;
        }

        return gmp_strval(gmp_init(substr($value, 2), 16), 10);
    }

    private function rpc(string $url, string $method, array $params): mixed
    {
        $response = Http::timeout(15)
            ->acceptJson()
            ->withOptions(['allow_redirects' => false])
            ->post($url, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => $method,
                'params' => $params,
            ]);

        if (! $response->successful()
            || strlen($response->body()) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('Sender transaction RPC unavailable.');
        }

        try {
            $body = $response->json();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Sender transaction RPC malformed.',
                previous: $exception
            );
        }

        if (! is_array($body)
            || array_key_exists('error', $body)
            || ! array_key_exists('result', $body)) {
            throw new RuntimeException('Sender transaction RPC malformed.');
        }

        return $body['result'];
    }

    private function checked(
        PaymentFeeDelegationAttempt $attempt,
        string $result,
        string $diagnostic
    ): string {
        $this->metadata($attempt, [
            'last_checked_at' => now(),
            'diagnostic_code' => $diagnostic,
        ]);

        return $this->resolutionResult($attempt, $result);
    }

    private function transition(
        PaymentFeeDelegationAttempt $attempt,
        string $state,
        array $attributes
    ): void {
        DB::transaction(function () use ($attempt, $state, $attributes): void {
            $locked = PaymentFeeDelegationAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->transitionTo($state, $attributes);
        }, 3);
        $attempt->refresh();
    }

    private function metadata(
        PaymentFeeDelegationAttempt $attempt,
        array $attributes
    ): void {
        PaymentFeeDelegationAttempt::query()
            ->whereKey($attempt->id)
            ->update($attributes);
        $attempt->refresh();
    }

    private function resolutionResult(
        PaymentFeeDelegationAttempt $attempt,
        string $result
    ): string {
        Log::info('fee_delegation_resolution', [
            'payment_id' => $attempt->payment_id,
            'attempt_id' => $attempt->id,
            'provider' => $attempt->provider,
            'sender_tx_hash' => $attempt->sender_tx_hash,
            'tx_hash' => $attempt->tx_hash,
            'diagnostic_code' => $attempt->diagnostic_code,
            'state' => $attempt->state,
            'resolution_result' => $result,
        ]);

        return $result;
    }
}
