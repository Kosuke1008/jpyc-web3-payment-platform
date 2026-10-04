<?php

namespace App\Services\Payments;

final class MainnetPaymentAuthorization
{
    private const DOMAIN = 'livt-mainnet-payment-v1';

    public function issue(
        int $paymentId,
        string $expiresAt,
        string $senderTransactionHash
    ): string {
        if ($paymentId < 1
            || preg_match('/\A0x[0-9a-f]{64}\z/', $senderTransactionHash) !== 1
            || $expiresAt === '') {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::CONFIGURATION_ERROR
            );
        }

        return hash_hmac(
            'sha256',
            self::canonical($paymentId, $expiresAt, $senderTransactionHash),
            self::keyBytes()
        );
    }

    public static function keyId(): string
    {
        return 'sha256:'.substr(hash('sha256', self::keyBytes()), 0, 16);
    }

    public static function canonical(
        int $paymentId,
        string $expiresAt,
        string $senderTransactionHash
    ): string {
        return self::DOMAIN."\n"
            .$paymentId."\n"
            .$expiresAt."\n"
            .strtolower($senderTransactionHash);
    }

    private static function keyBytes(): string
    {
        $key = config(
            'services.fee_delegation.self_hosted_mainnet.authorization_key'
        );
        if (! is_string($key)
            || preg_match('/\A[0-9a-fA-F]{64}\z/', $key) !== 1) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::CONFIGURATION_ERROR
            );
        }
        $binary = hex2bin($key);
        if ($binary === false) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::CONFIGURATION_ERROR
            );
        }

        return $binary;
    }
}
