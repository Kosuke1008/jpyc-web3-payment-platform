<?php

namespace App\Services\Payments;

use InvalidArgumentException;
use kornrunner\Keccak;

final class KaiaSenderTransactionHash
{
    public function fromSenderSignedRlp(string $raw): string
    {
        if (preg_match('/\A0x[0-9a-fA-F]+\z/', $raw) !== 1
            || strlen($raw) % 2 !== 0) {
            throw new InvalidArgumentException('Invalid sender transaction RLP.');
        }

        $bytes = hex2bin(substr($raw, 2));

        if ($bytes === false || $bytes === '') {
            throw new InvalidArgumentException('Invalid sender transaction RLP.');
        }

        // Kaia SenderTxHash is Keccak-256(SenderTxHashRLP). The Wallet output
        // is exactly that sender-only RLP and contains no fee-payer fields.
        return '0x'.Keccak::hash($bytes, 256);
    }
}
