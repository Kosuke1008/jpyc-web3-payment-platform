<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $mysql = DB::getDriverName() === 'mysql';

        Schema::create('payment_fee_delegation_attempts', function (Blueprint $table) use ($mysql) {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
            $table->foreignId('requester_user_id')->constrained('users')->restrictOnDelete();
            $network = $table->string('network', 32);
            $table->unsignedBigInteger('chain_id');
            $provider = $table->string('provider', 32);
            $sender = $table->char('sender_address', 42);
            $senderTxHash = $table->char('sender_tx_hash', 66);
            $txHash = $table->char('tx_hash', 66)->nullable();
            $fingerprint = $table->char('request_fingerprint', 64);
            $state = $table->string('state', 32);
            $table->unsignedSmallInteger('provider_http_status')->nullable();
            $diagnostic = $table->string('diagnostic_code', 64)->nullable();
            $senderNonce = $table->string('sender_nonce', 20);
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('submitting_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('receipt_observed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['chain_id', 'sender_tx_hash'],
                'fee_delegation_sender_tx_unique'
            );
            $table->unique(
                ['chain_id', 'request_fingerprint'],
                'fee_delegation_fingerprint_unique'
            );
            $table->index(
                ['state', 'last_checked_at'],
                'fee_delegation_resolution_index'
            );

            if ($mysql) {
                foreach ([
                    $network,
                    $provider,
                    $sender,
                    $senderTxHash,
                    $txHash,
                    $fingerprint,
                    $state,
                    $diagnostic,
                    $senderNonce,
                ] as $column) {
                    $column->charset('ascii')->collation('ascii_bin');
                }
            }
        });

        if ($mysql) {
            DB::statement(<<<'SQL'
                ALTER TABLE payment_fee_delegation_attempts
                ADD CONSTRAINT fee_delegation_attempt_values_check CHECK (
                    network IN ('kairos', 'kaia-mainnet')
                    AND chain_id > 0
                    AND provider IN ('self-hosted', 'kaia-managed')
                    AND sender_address REGEXP BINARY '^0x[0-9a-f]{40}$'
                    AND sender_tx_hash REGEXP BINARY '^0x[0-9a-f]{64}$'
                    AND (tx_hash IS NULL OR tx_hash REGEXP BINARY '^0x[0-9a-f]{64}$')
                    AND request_fingerprint REGEXP BINARY '^[0-9a-f]{64}$'
                    AND state IN (
                        'reserved', 'validated', 'submitting', 'submitted',
                        'receipt_observed', 'confirmed', 'rejected',
                        'unknown_submission', 'reverted', 'failed'
                    )
                    AND sender_nonce REGEXP BINARY '^(0|[1-9][0-9]{0,19})$'
                    AND (
                        provider_http_status IS NULL
                        OR provider_http_status BETWEEN 100 AND 599
                    )
                )
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE payment_fee_delegation_attempts
                ADD CONSTRAINT fee_delegation_diagnostic_check CHECK (
                    diagnostic_code IS NULL
                    OR diagnostic_code REGEXP BINARY '^[a-z0-9_]{1,64}$'
                )
            SQL);
        }
    }

    public function down(): void
    {
        // Submission attempts are payment audit data and are retained.
    }
};
