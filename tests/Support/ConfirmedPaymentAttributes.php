<?php

namespace Tests\Support;

final class ConfirmedPaymentAttributes
{
    /** @return array<string, mixed> */
    public static function forChain(int $chainId, ?string $txHash = null): array
    {
        return [
            'tx_hash' => $txHash ?? '0x'.str_repeat('a', 64),
            'paid_at' => now(),
            'observed_chain_id' => $chainId,
            'confirmed_block_number' => 16,
            'confirmed_block_hash' => '0x'.str_repeat('b', 64),
            'receipt_status' => 1,
            'payer_address' => '0x'.str_repeat('3', 40),
            'transfer_log_index' => 0,
            'chain_confirmed_at' => now(),
            'verified_at' => now(),
            'reconciliation_status' => 'pending',
        ];
    }
}
