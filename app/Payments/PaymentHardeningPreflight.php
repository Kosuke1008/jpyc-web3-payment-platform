<?php

namespace App\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class PaymentHardeningPreflight
{
    private const REQUIRED_COLUMNS = [
        'network',
        'network_profile_version',
        'chain_id',
        'token_contract',
        'token_symbol',
        'token_decimals',
        'recipient_address',
        'display_amount',
        'atomic_amount',
        'expires_at',
        'tx_hash',
        'observed_chain_id',
        'confirmed_block_number',
        'confirmed_block_hash',
        'receipt_status',
        'payer_address',
        'transfer_log_index',
        'chain_confirmed_at',
        'verified_at',
    ];

    public function assertSafe(): void
    {
        if (! Schema::hasColumns('payments', self::REQUIRED_COLUMNS)) {
            throw new RuntimeException(
                'Payment hardening refused: Phase 2/3 columns are missing.'
            );
        }

        $duplicate = DB::table('payments')
            ->select('chain_id', 'tx_hash')
            ->whereNotNull('tx_hash')
            ->groupBy('chain_id', 'tx_hash')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException(
                'Payment hardening refused: duplicate same-chain transaction hashes exist.'
            );
        }

        foreach (DB::table('payments')->orderBy('id')->lazyById() as $payment) {
            try {
                $snapshot = PaymentSnapshot::fromRecord($payment);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    "Payment hardening refused: Payment {$payment->id} lacks a defensible snapshot.",
                    previous: $exception
                );
            }

            if ($payment->tx_hash !== null
                && preg_match('/\A0x[0-9a-f]{64}\z/', $payment->tx_hash) !== 1) {
                throw new RuntimeException(
                    "Payment hardening refused: Payment {$payment->id} has a non-canonical transaction hash."
                );
            }

            if ($payment->status === 'confirmed') {
                try {
                    $evidence = PaymentConfirmationEvidence::fromRecord($payment);

                    if ($evidence->observedChainId !== $snapshot->chainId) {
                        throw new RuntimeException(
                            'Observed chain does not match Payment snapshot.'
                        );
                    }
                } catch (Throwable $exception) {
                    throw new RuntimeException(
                        "Payment hardening refused: Payment {$payment->id} lacks confirmation evidence.",
                        previous: $exception
                    );
                }
            }
        }
    }
}
