<?php

namespace App\Services\Payments;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class PaymentTransactionVerifier
{
    private const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    private const RECEIPT_ATTEMPTS = 10;

    private const RECEIPT_RETRY_MICROSECONDS = 500_000;

    public function verifyAndConfirm(
        int|string $paymentId,
        string $transactionHash,
        int $userId
    ): void {
        $normalizedHash = $this->normalizeTransactionHash($transactionHash);
        $payment = $this->loadEligiblePayment($paymentId, $normalizedHash);
        $rpcUrl = $this->configuredRpcUrl();
        $tokenContract = $this->configuredTokenContract();

        $this->verifyChainId($rpcUrl);

        $receipt = $this->fetchSuccessfulReceipt($rpcUrl, $normalizedHash);
        $walletAddress = $this->storeWalletAddress($payment->store_id);

        $this->assertMatchingTransfer(
            $receipt,
            $tokenContract,
            $walletAddress,
            $this->expectedAtomicAmount($payment->amount)
        );

        try {
            DB::transaction(function () use (
                $paymentId,
                $normalizedHash,
                $userId,
                $receipt,
                $tokenContract
            ): void {
                $payment = DB::table('payments')
                    ->where('id', $paymentId)
                    ->lockForUpdate()
                    ->first();

                $this->assertPaymentEligible($payment, $normalizedHash);

                $walletAddress = DB::table('wallets')
                    ->where('store_id', $payment->store_id)
                    ->lockForUpdate()
                    ->value('address');

                $this->assertMatchingTransfer(
                    $receipt,
                    $tokenContract,
                    $walletAddress,
                    $this->expectedAtomicAmount($payment->amount)
                );

                $updated = DB::table('payments')
                    ->where('id', $paymentId)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'confirmed',
                        'tx_hash' => $normalizedHash,
                        'paid_at' => now(),
                        'user_id' => $userId,
                    ]);

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

    private function loadEligiblePayment(
        int|string $paymentId,
        string $transactionHash
    ): object {
        $payment = DB::table('payments')->where('id', $paymentId)->first();

        $this->assertPaymentEligible($payment, $transactionHash);

        return $payment;
    }

    private function assertPaymentEligible(
        ?object $payment,
        string $transactionHash
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

        if ($payment->expires_at && now()->gt($payment->expires_at)) {
            throw new PaymentVerificationException(
                PaymentVerificationException::PAYMENT_EXPIRED
            );
        }

        if (DB::table('payments')
            ->where('tx_hash', $transactionHash)
            ->where('id', '<>', $payment->id)
            ->exists()) {
            throw new PaymentVerificationException(
                PaymentVerificationException::DUPLICATE_TRANSACTION_HASH
            );
        }
    }

    private function configuredRpcUrl(): string
    {
        $rpcUrl = config('services.web3.rpc_url');

        if (! is_string($rpcUrl) || $rpcUrl === '') {
            throw new PaymentVerificationException(
                PaymentVerificationException::RPC_URL_NOT_CONFIGURED
            );
        }

        return $rpcUrl;
    }

    private function configuredTokenContract(): string
    {
        $contractAddress = config('services.web3.erc20_contract_address');

        if (! is_string($contractAddress)
            || preg_match('/\A0x[0-9a-fA-F]{40}\z/', $contractAddress) !== 1) {
            throw new PaymentVerificationException(
                PaymentVerificationException::TOKEN_CONTRACT_NOT_CONFIGURED
            );
        }

        return strtolower($contractAddress);
    }

    private function verifyChainId(string $rpcUrl): void
    {
        $expectedChainId = $this->normalizeChainId(
            config('services.web3.chain_id')
        );

        if ($expectedChainId === null) {
            throw new PaymentVerificationException(
                PaymentVerificationException::CHAIN_ID_NOT_CONFIGURED
            );
        }

        try {
            $response = $this->rpcRequest($rpcUrl, 'eth_chainId');
            $data = $response->json();
        } catch (ConnectionException) {
            throw new PaymentVerificationException(
                PaymentVerificationException::INVALID_CHAIN_ID_RESPONSE
            );
        }

        if (! is_array($data) || ! array_key_exists('result', $data)) {
            throw new PaymentVerificationException(
                PaymentVerificationException::INVALID_CHAIN_ID_RESPONSE
            );
        }

        $actualChainId = $this->normalizeChainId($data['result']);

        if ($actualChainId === null) {
            throw new PaymentVerificationException(
                PaymentVerificationException::INVALID_CHAIN_ID_RESPONSE
            );
        }

        if ($actualChainId !== $expectedChainId) {
            throw new PaymentVerificationException(
                PaymentVerificationException::CHAIN_ID_MISMATCH
            );
        }
    }

    private function normalizeChainId(mixed $chainId): ?string
    {
        if (is_int($chainId)) {
            return $chainId > 0 ? (string) $chainId : null;
        }

        if (! is_string($chainId)) {
            return null;
        }

        if (preg_match('/\A0[xX]([0-9a-fA-F]+)\z/', $chainId, $matches) === 1) {
            $digits = $matches[1];
            $base = 16;
        } elseif (preg_match('/\A[0-9]+\z/', $chainId) === 1) {
            $digits = $chainId;
            $base = 10;
        } else {
            return null;
        }

        try {
            $value = gmp_init($digits, $base);
        } catch (Throwable) {
            return null;
        }

        return gmp_cmp($value, 0) > 0 ? gmp_strval($value, 10) : null;
    }

    private function fetchSuccessfulReceipt(
        string $rpcUrl,
        string $transactionHash
    ): array {
        $receipt = null;

        for ($attempt = 0; $attempt < self::RECEIPT_ATTEMPTS; $attempt++) {
            try {
                $response = $this->rpcRequest(
                    $rpcUrl,
                    'eth_getTransactionReceipt',
                    [$transactionHash]
                );
                $data = $response->json();
            } catch (ConnectionException) {
                $data = null;
            }

            if (is_array($data)
                && array_key_exists('result', $data)
                && $data['result'] !== null) {
                $receipt = $data['result'];
                break;
            }

            usleep(self::RECEIPT_RETRY_MICROSECONDS);
        }

        if ($receipt === null) {
            throw new PaymentVerificationException(
                PaymentVerificationException::TRANSACTION_NOT_FOUND
            );
        }

        if (! is_array($receipt)
            || ! array_key_exists('status', $receipt)
            || ! is_string($receipt['status'])) {
            throw new PaymentVerificationException(
                PaymentVerificationException::TRANSACTION_FAILED
            );
        }

        if ($receipt['status'] !== '0x1') {
            throw new PaymentVerificationException(
                PaymentVerificationException::TRANSACTION_FAILED
            );
        }

        if (! isset($receipt['logs']) || ! is_array($receipt['logs'])) {
            throw new PaymentVerificationException(
                PaymentVerificationException::INVALID_TRANSACTION
            );
        }

        return $receipt;
    }

    private function rpcRequest(
        string $rpcUrl,
        string $method,
        array $parameters = []
    ): Response {
        return Http::timeout(30)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ],
            ])
            ->post($rpcUrl, [
                'jsonrpc' => '2.0',
                'method' => $method,
                'params' => $parameters,
                'id' => 1,
            ]);
    }

    private function storeWalletAddress(int|string $storeId): mixed
    {
        return DB::table('wallets')
            ->where('store_id', $storeId)
            ->value('address');
    }

    private function expectedAtomicAmount(mixed $paymentAmount): string
    {
        $decimals = config('services.web3.token_decimals', 18);
        $paymentAmount = (string) $paymentAmount;

        if ((! is_int($decimals) && ! is_string($decimals))
            || preg_match('/\A[0-9]+\z/', (string) $decimals) !== 1
            || preg_match('/\A[0-9]+\z/', $paymentAmount) !== 1) {
            throw new PaymentVerificationException(
                PaymentVerificationException::INVALID_TRANSACTION
            );
        }

        $decimalCount = gmp_init((string) $decimals, 10);

        if (gmp_cmp($decimalCount, 255) > 0) {
            throw new PaymentVerificationException(
                PaymentVerificationException::INVALID_TRANSACTION
            );
        }

        return bcmul(
            $paymentAmount,
            bcpow('10', gmp_strval($decimalCount, 10), 0),
            0
        );
    }

    private function assertMatchingTransfer(
        array $receipt,
        string $tokenContract,
        mixed $walletAddress,
        string $expectedAmount
    ): void {
        if (! is_string($walletAddress)
            || preg_match('/\A0x[0-9a-fA-F]{40}\z/', $walletAddress) !== 1) {
            throw new PaymentVerificationException(
                PaymentVerificationException::INVALID_TRANSACTION
            );
        }

        foreach ($receipt['logs'] as $log) {
            if (! is_array($log)
                || ! isset($log['address'], $log['topics'], $log['data'])
                || ! is_string($log['address'])
                || ! is_array($log['topics'])
                || ! is_string($log['data'])) {
                continue;
            }

            if (preg_match('/\A0x[0-9a-fA-F]{40}\z/', $log['address']) !== 1
                || strcasecmp($log['address'], $tokenContract) !== 0) {
                continue;
            }

            if (! isset($log['topics'][0], $log['topics'][1], $log['topics'][2])
                || ! is_string($log['topics'][0])
                || ! is_string($log['topics'][1])
                || ! is_string($log['topics'][2])
                || strcasecmp($log['topics'][0], self::TRANSFER_TOPIC) !== 0
                || preg_match('/\A0x[0-9a-fA-F]{64}\z/', $log['topics'][1]) !== 1
                || preg_match('/\A0x[0-9a-fA-F]{64}\z/', $log['topics'][2]) !== 1
                || preg_match('/\A0x[0-9a-fA-F]{64}\z/', $log['data']) !== 1) {
                continue;
            }

            $recipient = '0x'.substr(strtolower($log['topics'][2]), -40);

            try {
                $amount = gmp_strval(gmp_init(substr($log['data'], 2), 16), 10);
            } catch (Throwable) {
                continue;
            }

            if (strcasecmp($recipient, $walletAddress) === 0
                && bccomp($amount, $expectedAmount, 0) === 0) {
                return;
            }
        }

        throw new PaymentVerificationException(
            PaymentVerificationException::INVALID_TRANSACTION
        );
    }
}
