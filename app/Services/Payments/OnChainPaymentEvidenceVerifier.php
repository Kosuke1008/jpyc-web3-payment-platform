<?php

namespace App\Services\Payments;

use App\Blockchain\KaiaFinalityPolicy;
use App\Blockchain\NetworkProfileRegistry;
use App\Payments\PaymentConfirmationEvidence;
use App\Payments\PaymentSnapshot;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

final class OnChainPaymentEvidenceVerifier
{
    private const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    private const RECEIPT_ATTEMPTS = 10;

    private const RECEIPT_RETRY_MICROSECONDS = 500_000;

    public function __construct(
        private readonly NetworkProfileRegistry $networks,
        private readonly KaiaFinalityPolicy $finalityPolicy
    ) {}

    public function verify(
        PaymentSnapshot $snapshot,
        string $transactionHash,
        DateTimeInterface|string $createdAt
    ): PaymentConfirmationEvidence {
        $requestedHash = $this->hash($transactionHash);
        $profile = $this->networks->get($snapshot->network);

        if ($profile->chainId !== $snapshot->chainId) {
            $this->invalidEvidence();
        }

        $this->networks->assertPaymentExecutionAllowed($profile);
        $observedChainId = $this->verifyChainId(
            $profile->rpcUrl,
            $snapshot->chainId
        );
        $transaction = $this->rpcResult(
            $profile->rpcUrl,
            'eth_getTransactionByHash',
            [$requestedHash]
        );

        if ($transaction === null) {
            throw new PaymentVerificationException(
                PaymentVerificationException::TRANSACTION_NOT_FOUND
            );
        }

        $transactionEvidence = $this->transactionEvidence(
            $transaction,
            $requestedHash
        );
        $receipt = $this->fetchReceipt($profile->rpcUrl, $requestedHash);
        $receiptEvidence = $this->receiptEvidence($receipt, $requestedHash);

        if ($transactionEvidence['block_number'] !== $receiptEvidence['block_number']
            || $transactionEvidence['block_hash'] !== $receiptEvidence['block_hash']) {
            $this->invalidEvidence();
        }

        $block = $this->rpcResult(
            $profile->rpcUrl,
            'eth_getBlockByNumber',
            [$receiptEvidence['block_number_hex'], false]
        );
        $blockTimestamp = $this->canonicalBlockTimestamp(
            $block,
            $receiptEvidence['block_number'],
            $receiptEvidence['block_hash'],
            $requestedHash
        );
        $this->finalityPolicy->assertPaymentWindow(
            $snapshot->network,
            $blockTimestamp,
            $createdAt,
            $snapshot->expiresAt
        );
        $transfer = $this->matchingTransfer(
            $receiptEvidence['logs'],
            $snapshot,
            $requestedHash,
            $receiptEvidence['block_number'],
            $receiptEvidence['block_hash']
        );

        if ($transfer['payer'] !== $transactionEvidence['sender']) {
            $this->invalidEvidence();
        }

        return PaymentConfirmationEvidence::create(
            observedChainId: $observedChainId,
            transactionHash: $requestedHash,
            blockNumber: $receiptEvidence['block_number'],
            blockHash: $receiptEvidence['block_hash'],
            receiptStatus: 1,
            payerAddress: $transfer['payer'],
            transferLogIndex: $transfer['log_index'],
            chainConfirmedAt: $blockTimestamp,
            verifiedAt: now(),
        );
    }

    private function verifyChainId(string $rpcUrl, int $expected): int
    {
        $actual = $this->quantity($this->rpcResult($rpcUrl, 'eth_chainId'));

        if ($actual === null) {
            throw new PaymentVerificationException(
                PaymentVerificationException::INVALID_CHAIN_ID_RESPONSE
            );
        }

        if ($actual !== $expected) {
            throw new PaymentVerificationException(
                PaymentVerificationException::CHAIN_ID_MISMATCH
            );
        }

        return $actual;
    }

    /** @return array{block_number: int, block_hash: string, sender: string} */
    private function transactionEvidence(mixed $transaction, string $requestedHash): array
    {
        if (! is_array($transaction)
            || ! isset($transaction['hash'], $transaction['blockNumber'], $transaction['blockHash'])
            || $this->nullableHash($transaction['hash']) !== $requestedHash) {
            $this->invalidEvidence();
        }

        $blockNumber = $this->quantity($transaction['blockNumber']);
        $blockHash = $this->nullableHash($transaction['blockHash']);
        $sender = $this->address($transaction['from'] ?? null);

        if ($blockNumber === null
            || $blockNumber < 1
            || $blockHash === null
            || $sender === null
            || $sender === '0x0000000000000000000000000000000000000000') {
            $this->invalidEvidence();
        }

        return [
            'block_number' => $blockNumber,
            'block_hash' => $blockHash,
            'sender' => $sender,
        ];
    }

    /** @return array{block_number: int, block_number_hex: string, block_hash: string, logs: array<int, mixed>} */
    private function receiptEvidence(mixed $receipt, string $requestedHash): array
    {
        if (! is_array($receipt)
            || ! isset($receipt['transactionHash'], $receipt['blockNumber'], $receipt['blockHash'], $receipt['status'])
            || ! array_key_exists('logs', $receipt)
            || ! is_array($receipt['logs'])
            || $this->nullableHash($receipt['transactionHash']) !== $requestedHash) {
            $this->invalidEvidence();
        }

        $status = $this->quantity($receipt['status']);

        if ($status !== 1) {
            throw new PaymentVerificationException(
                PaymentVerificationException::TRANSACTION_FAILED
            );
        }

        $blockNumber = $this->quantity($receipt['blockNumber']);
        $blockHash = $this->nullableHash($receipt['blockHash']);

        if ($blockNumber === null || $blockNumber < 1 || $blockHash === null) {
            $this->invalidEvidence();
        }

        return [
            'block_number' => $blockNumber,
            'block_number_hex' => '0x'.dechex($blockNumber),
            'block_hash' => $blockHash,
            'logs' => $receipt['logs'],
        ];
    }

    private function fetchReceipt(string $rpcUrl, string $transactionHash): mixed
    {
        for ($attempt = 0; $attempt < self::RECEIPT_ATTEMPTS; $attempt++) {
            $receipt = $this->rpcResult(
                $rpcUrl,
                'eth_getTransactionReceipt',
                [$transactionHash]
            );

            if ($receipt !== null) {
                return $receipt;
            }

            usleep(self::RECEIPT_RETRY_MICROSECONDS);
        }

        throw new PaymentVerificationException(
            PaymentVerificationException::TRANSACTION_NOT_FOUND
        );
    }

    private function canonicalBlockTimestamp(
        mixed $block,
        int $expectedNumber,
        string $expectedHash,
        string $transactionHash
    ): CarbonImmutable {
        if (! is_array($block)
            || ! isset($block['number'], $block['hash'], $block['timestamp'])
            || ! isset($block['transactions'])
            || ! is_array($block['transactions'])
            || $this->quantity($block['number']) !== $expectedNumber
            || $this->nullableHash($block['hash']) !== $expectedHash
            || ! in_array($transactionHash, array_map(
                fn (mixed $hash): ?string => $this->nullableHash($hash),
                $block['transactions']
            ), true)) {
            $this->invalidEvidence();
        }

        $timestamp = $this->quantity($block['timestamp']);

        if ($timestamp === null || $timestamp < 1) {
            $this->invalidEvidence();
        }

        return CarbonImmutable::createFromTimestampUTC($timestamp);
    }

    /** @return array{payer: string, log_index: int} */
    private function matchingTransfer(
        array $logs,
        PaymentSnapshot $snapshot,
        string $transactionHash,
        int $blockNumber,
        string $blockHash
    ): array {
        foreach ($logs as $log) {
            if (! is_array($log)) {
                continue;
            }

            if (($log['removed'] ?? false) === true) {
                throw new PaymentVerificationException(
                    PaymentVerificationException::INVALID_TRANSACTION
                );
            }

            if ((array_key_exists('removed', $log)
                    && ! is_bool($log['removed']))
                || (array_key_exists('transactionHash', $log)
                    && $this->nullableHash($log['transactionHash']) !== $transactionHash)
                || (array_key_exists('blockHash', $log)
                    && $this->nullableHash($log['blockHash']) !== $blockHash)
                || (array_key_exists('blockNumber', $log)
                    && $this->quantity($log['blockNumber']) !== $blockNumber)) {
                $this->invalidEvidence();
            }

            if (! isset($log['address'], $log['topics'], $log['data'], $log['logIndex'])
                || ! is_string($log['address'])
                || ! is_array($log['topics'])
                || ! is_string($log['data'])
                || strcasecmp($log['address'], $snapshot->tokenContract) !== 0
                || count($log['topics']) < 3
                || ! is_string($log['topics'][0])
                || ! is_string($log['topics'][1])
                || ! is_string($log['topics'][2])
                || strcasecmp($log['topics'][0], self::TRANSFER_TOPIC) !== 0
                || preg_match('/\A0x[0-9a-fA-F]{64}\z/', $log['topics'][1]) !== 1
                || preg_match('/\A0x[0-9a-fA-F]{64}\z/', $log['topics'][2]) !== 1
                || preg_match('/\A0x[0-9a-fA-F]{64}\z/', $log['data']) !== 1) {
                continue;
            }

            $payer = '0x'.substr(strtolower($log['topics'][1]), -40);
            $recipient = '0x'.substr(strtolower($log['topics'][2]), -40);
            $logIndex = $this->quantity($log['logIndex']);

            try {
                $amount = gmp_strval(
                    gmp_init(substr($log['data'], 2), 16),
                    10
                );
            } catch (Throwable) {
                continue;
            }

            if ($payer !== '0x0000000000000000000000000000000000000000'
                && strcasecmp($recipient, $snapshot->recipientAddress) === 0
                && bccomp($amount, $snapshot->atomicAmount, 0) === 0
                && $logIndex !== null) {
                return ['payer' => $payer, 'log_index' => $logIndex];
            }
        }

        throw new PaymentVerificationException(
            PaymentVerificationException::INVALID_TRANSACTION
        );
    }

    private function hash(string $value): string
    {
        $hash = $this->nullableHash($value);

        if ($hash === null) {
            throw new PaymentVerificationException(
                PaymentVerificationException::INVALID_TRANSACTION_HASH
            );
        }

        return $hash;
    }

    private function nullableHash(mixed $value): ?string
    {
        return is_string($value)
            && preg_match('/\A0x[0-9a-fA-F]{64}\z/', $value) === 1
                ? strtolower($value)
                : null;
    }

    private function address(mixed $value): ?string
    {
        return is_string($value)
            && preg_match('/\A0x[0-9a-fA-F]{40}\z/', $value) === 1
                ? strtolower($value)
                : null;
    }

    private function quantity(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value)
            || preg_match('/\A0x[0-9a-fA-F]+\z/', $value) !== 1) {
            return null;
        }

        try {
            $number = gmp_init(substr($value, 2), 16);
        } catch (Throwable) {
            return null;
        }

        return gmp_cmp($number, PHP_INT_MAX) <= 0
            ? (int) gmp_strval($number, 10)
            : null;
    }

    private function rpcResult(
        string $rpcUrl,
        string $method,
        array $parameters = []
    ): mixed {
        try {
            $response = $this->rpcRequest($rpcUrl, $method, $parameters);
        } catch (ConnectionException $exception) {
            throw new PaymentVerificationException(
                PaymentVerificationException::RPC_TRANSPORT_FAILURE,
                rpcMethod: $method,
                previous: $exception
            );
        }

        if (! $response->successful()) {
            throw new PaymentVerificationException(
                PaymentVerificationException::RPC_TRANSPORT_FAILURE,
                rpcMethod: $method,
                upstreamStatus: $response->status()
            );
        }

        $body = $response->body();

        if (trim($body) === '') {
            throw new PaymentVerificationException(
                PaymentVerificationException::MALFORMED_RPC_RESPONSE,
                rpcMethod: $method
            );
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PaymentVerificationException(
                PaymentVerificationException::MALFORMED_RPC_RESPONSE,
                rpcMethod: $method,
                previous: $exception
            );
        }

        if (! is_array($data)) {
            throw new PaymentVerificationException(
                PaymentVerificationException::MALFORMED_RPC_RESPONSE,
                rpcMethod: $method
            );
        }

        if (array_key_exists('error', $data) && $data['error'] !== null) {
            $providerCode = is_array($data['error'])
                && array_key_exists('code', $data['error'])
                && is_int($data['error']['code'])
                    ? $data['error']['code']
                    : null;

            throw new PaymentVerificationException(
                PaymentVerificationException::JSON_RPC_PROVIDER_ERROR,
                rpcMethod: $method,
                providerCode: $providerCode
            );
        }

        if (! array_key_exists('result', $data)) {
            throw new PaymentVerificationException(
                PaymentVerificationException::MALFORMED_RPC_RESPONSE,
                rpcMethod: $method
            );
        }

        return $data['result'];
    }

    private function rpcRequest(
        string $rpcUrl,
        string $method,
        array $parameters = []
    ): Response {
        return Http::timeout(30)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->withOptions([
                'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
            ])
            ->post($rpcUrl, [
                'jsonrpc' => '2.0',
                'method' => $method,
                'params' => $parameters,
                'id' => 1,
            ]);
    }

    private function invalidEvidence(): never
    {
        throw new PaymentVerificationException(
            PaymentVerificationException::INVALID_TRANSACTION
        );
    }
}
