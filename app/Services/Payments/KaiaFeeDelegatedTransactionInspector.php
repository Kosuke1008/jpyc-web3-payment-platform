<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

class KaiaFeeDelegatedTransactionInspector implements FeeDelegatedTransactionInspector
{
    private const TRANSACTION_TYPE = 0x31;

    private const TRANSFER_SELECTOR = 'a9059cbb';

    private const FIELD_COUNT = 8;

    private const SECP256K1_ORDER = '115792089237316195423570985008687907852837564279074904382605163141518161494337';

    private const SECP256K1_HALF_ORDER = '57896044618658097711785492504343953926418782139537452191302581570759080747168';

    public function inspect(string $senderSignedTransaction): SponsoredTransfer
    {
        $rawBytes = $this->decodeRawTransaction($senderSignedTransaction);

        if (ord($rawBytes[0]) !== self::TRANSACTION_TYPE) {
            $this->invalidTransaction();
        }

        $rlpBytes = substr($rawBytes, 1);
        $offset = 0;
        $fields = $this->decodeRlpItem($rlpBytes, $offset, 0);

        if (! is_array($fields)
            || count($fields) !== self::FIELD_COUNT
            || $offset !== strlen($rlpBytes)) {
            $this->invalidTransaction();
        }

        [$nonce, $gasPrice, $gas, $to, $value, $from, $input, $signatures]
            = $fields;

        $nonceValue = $this->assertUnsignedInteger($nonce);
        $gasPriceValue = $this->assertUnsignedInteger(
            $gasPrice,
            positive: true
        );
        $gasLimit = $this->assertUnsignedInteger($gas, positive: true);

        $this->assertIntegerBound(
            $nonceValue,
            '18446744073709551615'
        );
        $this->assertIntegerBound(
            $gasLimit,
            '18446744073709551615'
        );
        $this->assertIntegerBound(
            $gasPriceValue,
            '115792089237316195423570985008687907853269984665640564039457584007913129639935'
        );

        if (! is_string($to) || strlen($to) !== 20
            || ! is_string($from) || strlen($from) !== 20
            || ! is_string($value) || $value !== ''
            || ! is_string($input) || strlen($input) !== 68
            || ! is_array($signatures)) {
            $this->invalidTransaction();
        }

        $maxGas = $this->configuredMaxGas();

        if (gmp_cmp(gmp_init($gasLimit, 10), gmp_init($maxGas, 10)) > 0) {
            $this->invalidTransaction();
        }

        $chainId = $this->chainIdFromSignatures($signatures);
        $selector = bin2hex(substr($input, 0, 4));
        $recipientWord = substr($input, 4, 32);

        if ($selector !== self::TRANSFER_SELECTOR
            || substr($recipientWord, 0, 12) !== str_repeat("\0", 12)) {
            $this->invalidTransaction();
        }

        $sender = '0x'.bin2hex($from);
        $this->assertRecoveredSender(
            $this->recoverableTransaction($fields, $signatures),
            $sender
        );

        return new SponsoredTransfer(
            chainId: $chainId,
            sender: $sender,
            tokenContract: '0x'.bin2hex($to),
            recipient: '0x'.bin2hex(substr($recipientWord, 12)),
            atomicAmount: gmp_strval(
                gmp_init(bin2hex(substr($input, 36, 32)), 16),
                10
            ),
            gasLimit: $gasLimit
        );
    }

    private function decodeRawTransaction(string $raw): string
    {
        if (preg_match('/\A0x[0-9a-fA-F]+\z/', $raw) !== 1
            || strlen($raw) % 2 !== 0
            || strlen($raw) < 6
            || strlen($raw) > 20000) {
            $this->invalidTransaction();
        }

        $bytes = hex2bin(substr($raw, 2));

        if ($bytes === false || $bytes === '') {
            $this->invalidTransaction();
        }

        return $bytes;
    }

    /**
     * @return array<int, array|string>|string
     */
    private function decodeRlpItem(
        string $bytes,
        int &$offset,
        int $depth
    ): array|string {
        $totalLength = strlen($bytes);

        if ($depth > 3 || $offset >= $totalLength) {
            $this->invalidTransaction();
        }

        $prefix = ord($bytes[$offset++]);

        if ($prefix <= 0x7F) {
            return chr($prefix);
        }

        if ($prefix <= 0xB7) {
            $length = $prefix - 0x80;
            $value = $this->takeBytes($bytes, $offset, $length);

            if ($length === 1 && ord($value[0]) <= 0x7F) {
                $this->invalidTransaction();
            }

            return $value;
        }

        if ($prefix <= 0xBF) {
            $length = $this->decodeLongLength(
                $bytes,
                $offset,
                $prefix - 0xB7
            );

            if ($length < 56) {
                $this->invalidTransaction();
            }

            return $this->takeBytes($bytes, $offset, $length);
        }

        if ($prefix <= 0xF7) {
            return $this->decodeRlpList(
                $bytes,
                $offset,
                $prefix - 0xC0,
                $depth
            );
        }

        $length = $this->decodeLongLength(
            $bytes,
            $offset,
            $prefix - 0xF7
        );

        if ($length < 56) {
            $this->invalidTransaction();
        }

        return $this->decodeRlpList($bytes, $offset, $length, $depth);
    }

    /**
     * @return array<int, array|string>
     */
    private function decodeRlpList(
        string $bytes,
        int &$offset,
        int $length,
        int $depth
    ): array {
        $end = $offset + $length;

        if ($end < $offset || $end > strlen($bytes)) {
            $this->invalidTransaction();
        }

        $items = [];

        while ($offset < $end) {
            $items[] = $this->decodeRlpItem(
                $bytes,
                $offset,
                $depth + 1
            );

            if ($offset > $end) {
                $this->invalidTransaction();
            }
        }

        if ($offset !== $end) {
            $this->invalidTransaction();
        }

        return $items;
    }

    private function decodeLongLength(
        string $bytes,
        int &$offset,
        int $lengthOfLength
    ): int {
        if ($lengthOfLength < 1 || $lengthOfLength > 4) {
            $this->invalidTransaction();
        }

        $encodedLength = $this->takeBytes(
            $bytes,
            $offset,
            $lengthOfLength
        );

        if (ord($encodedLength[0]) === 0) {
            $this->invalidTransaction();
        }

        $length = 0;

        for ($index = 0; $index < $lengthOfLength; $index++) {
            $length = ($length * 256) + ord($encodedLength[$index]);
        }

        return $length;
    }

    private function takeBytes(
        string $bytes,
        int &$offset,
        int $length
    ): string {
        if ($length < 0
            || $offset + $length < $offset
            || $offset + $length > strlen($bytes)) {
            $this->invalidTransaction();
        }

        $value = substr($bytes, $offset, $length);
        $offset += $length;

        return $value;
    }

    private function assertUnsignedInteger(
        mixed $encoded,
        bool $positive = false
    ): string {
        if (! is_string($encoded)
            || ($encoded !== '' && ord($encoded[0]) === 0)) {
            $this->invalidTransaction();
        }

        $value = $encoded === ''
            ? '0'
            : gmp_strval(gmp_init(bin2hex($encoded), 16), 10);

        if ($positive && $value === '0') {
            $this->invalidTransaction();
        }

        return $value;
    }

    private function chainIdFromSignatures(array $signatures): int
    {
        if ($signatures === []) {
            $this->invalidTransaction();
        }

        $chainId = null;

        foreach ($signatures as $signature) {
            if (! is_array($signature) || count($signature) !== 3) {
                $this->invalidTransaction();
            }

            [$encodedV, $r, $s] = $signature;
            $v = $this->assertUnsignedInteger($encodedV, positive: true);

            if (! is_string($r) || ! is_string($s)
                || strlen($r) < 1 || strlen($r) > 32
                || strlen($s) < 1 || strlen($s) > 32
                || ord($r[0]) === 0 || ord($s[0]) === 0) {
                $this->invalidTransaction();
            }

            $this->assertSignatureScalars($r, $s);

            $vNumber = gmp_init($v, 10);

            if (gmp_cmp($vNumber, 35) < 0) {
                $this->invalidTransaction();
            }

            $derived = gmp_div_q(gmp_sub($vNumber, 35), 2);
            $derivedString = gmp_strval($derived, 10);
            $base = gmp_mul($derived, 2);
            $validV1 = gmp_add($base, 35);
            $validV2 = gmp_add($base, 36);

            if ((gmp_cmp($vNumber, $validV1) !== 0
                    && gmp_cmp($vNumber, $validV2) !== 0)
                || $derivedString !== '1001') {
                $this->invalidTransaction();
            }

            $chainId ??= 1001;
        }

        return $chainId ?? throw new PaymentSponsorshipException(
            PaymentSponsorshipException::INVALID_TRANSACTION
        );
    }

    private function assertIntegerBound(string $value, string $maximum): void
    {
        if (gmp_cmp(gmp_init($value, 10), gmp_init($maximum, 10)) > 0) {
            $this->invalidTransaction();
        }
    }

    private function configuredMaxGas(): string
    {
        $maxGas = config('services.fee_delegation.max_gas');

        if ((! is_int($maxGas) && ! is_string($maxGas))
            || preg_match('/\A[0-9]+\z/', (string) $maxGas) !== 1
            || gmp_cmp(gmp_init((string) $maxGas, 10), 1) < 0) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::CONFIGURATION_ERROR
            );
        }

        return (string) $maxGas;
    }

    private function assertSignatureScalars(string $r, string $s): void
    {
        $rValue = gmp_init(bin2hex($r), 16);
        $sValue = gmp_init(bin2hex($s), 16);

        if (gmp_cmp($rValue, 0) <= 0
            || gmp_cmp($rValue, gmp_init(self::SECP256K1_ORDER, 10)) >= 0
            || gmp_cmp($sValue, 0) <= 0
            || gmp_cmp(
                $sValue,
                gmp_init(self::SECP256K1_HALF_ORDER, 10)
            ) > 0) {
            $this->invalidTransaction();
        }
    }

    private function recoverableTransaction(
        array $fields,
        array $signatures
    ): string {
        $firstSignature = $signatures[0] ?? null;
        $encodedV = is_array($firstSignature)
            ? ($firstSignature[0] ?? null)
            : null;

        if (! is_string($encodedV)) {
            $this->invalidTransaction();
        }

        // Kaia's recovery RPC only decodes the complete 10-field form. These
        // fee-payer fields are verification-only: sender SigRLP excludes them,
        // and this synthetic transaction is never submitted to the network.
        $fields[] = str_repeat("\x11", 20);
        $fields[] = [[$encodedV, "\x01", "\x01"]];

        return '0x'.dechex(self::TRANSACTION_TYPE)
            .bin2hex($this->encodeRlpItem($fields));
    }

    private function encodeRlpItem(array|string $value): string
    {
        if (is_array($value)) {
            $payload = '';

            foreach ($value as $item) {
                $payload .= $this->encodeRlpItem($item);
            }

            return $this->encodeRlpPayload($payload, 0xC0, 0xF7);
        }

        if (strlen($value) === 1 && ord($value[0]) <= 0x7F) {
            return $value;
        }

        return $this->encodeRlpPayload($value, 0x80, 0xB7);
    }

    private function encodeRlpPayload(
        string $payload,
        int $shortOffset,
        int $longOffset
    ): string {
        $length = strlen($payload);

        if ($length <= 55) {
            return chr($shortOffset + $length).$payload;
        }

        $encodedLength = '';
        $remaining = $length;

        while ($remaining > 0) {
            $encodedLength = chr($remaining & 0xFF).$encodedLength;
            $remaining >>= 8;
        }

        return chr($longOffset + strlen($encodedLength))
            .$encodedLength
            .$payload;
    }

    private function assertRecoveredSender(string $raw, string $sender): void
    {
        $rpcUrl = config('services.web3.rpc_url');

        if (! is_string($rpcUrl) || $rpcUrl === '') {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::CONFIGURATION_ERROR
            );
        }

        try {
            $response = Http::timeout(30)->post($rpcUrl, [
                'jsonrpc' => '2.0',
                'method' => 'kaia_recoverFromTransaction',
                'params' => [$raw, 'latest'],
                'id' => 1,
            ]);
        } catch (ConnectionException $exception) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::RPC_UNAVAILABLE,
                externalMethod: 'kaia_recoverFromTransaction',
                previous: $exception
            );
        }

        if (! $response->successful()) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::RPC_UNAVAILABLE,
                externalMethod: 'kaia_recoverFromTransaction',
                upstreamStatus: $response->status()
            );
        }

        try {
            $body = json_decode(
                $response->body(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::INVALID_RPC_RESPONSE,
                externalMethod: 'kaia_recoverFromTransaction',
                previous: $exception
            );
        }

        $recovered = is_array($body) ? ($body['result'] ?? null) : null;

        if ((is_array($body) && array_key_exists('error', $body))
            || ! is_string($recovered)
            || preg_match('/\A0x[0-9a-fA-F]{40}\z/', $recovered) !== 1) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::INVALID_RPC_RESPONSE,
                externalMethod: 'kaia_recoverFromTransaction'
            );
        }

        if (strcasecmp($recovered, $sender) !== 0) {
            $this->invalidTransaction();
        }
    }

    private function invalidTransaction(): never
    {
        throw new PaymentSponsorshipException(
            PaymentSponsorshipException::INVALID_TRANSACTION
        );
    }
}
