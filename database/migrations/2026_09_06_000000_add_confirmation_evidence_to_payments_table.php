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

        Schema::table('payments', function (Blueprint $table) use ($mysql) {
            $table->unsignedBigInteger('observed_chain_id')->nullable()->after('atomic_amount');
            $table->unsignedBigInteger('confirmed_block_number')->nullable()->after('tx_hash');
            $blockHash = $table->char('confirmed_block_hash', 66)->nullable()->after('confirmed_block_number');
            $table->unsignedTinyInteger('receipt_status')->nullable()->after('confirmed_block_hash');
            $payer = $table->char('payer_address', 42)->nullable()->after('receipt_status');
            $table->unsignedInteger('transfer_log_index')->nullable()->after('payer_address');
            $table->timestamp('chain_confirmed_at')->nullable()->after('transfer_log_index');
            $table->timestamp('verified_at')->nullable()->after('chain_confirmed_at');
            $reconciliationStatus = $table->string('reconciliation_status', 32)->nullable()->after('verified_at');
            $reconciliationError = $table->string('reconciliation_error_code', 64)->nullable()->after('reconciliation_status');
            $table->timestamp('reconciled_at')->nullable()->after('reconciliation_error_code');
            $table->index(
                ['status', 'reconciliation_status', 'reconciled_at'],
                'payments_reconciliation_index'
            );

            if ($mysql) {
                foreach ([$blockHash, $payer, $reconciliationStatus, $reconciliationError] as $column) {
                    $column->charset('ascii')->collation('ascii_bin');
                }
            }
        });

        if ($mysql) {
            DB::statement(<<<'SQL'
                ALTER TABLE payments
                ADD CONSTRAINT payments_confirmation_evidence_presence_check CHECK (
                    (
                        observed_chain_id IS NULL
                        AND confirmed_block_number IS NULL
                        AND confirmed_block_hash IS NULL
                        AND receipt_status IS NULL
                        AND payer_address IS NULL
                        AND transfer_log_index IS NULL
                        AND chain_confirmed_at IS NULL
                        AND verified_at IS NULL
                    )
                    OR
                    (
                        observed_chain_id IS NOT NULL
                        AND tx_hash IS NOT NULL
                        AND confirmed_block_number IS NOT NULL
                        AND confirmed_block_hash IS NOT NULL
                        AND receipt_status = 1
                        AND payer_address IS NOT NULL
                        AND transfer_log_index IS NOT NULL
                        AND chain_confirmed_at IS NOT NULL
                        AND verified_at IS NOT NULL
                        AND reconciliation_status IS NOT NULL
                    )
                )
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE payments
                ADD CONSTRAINT payments_confirmation_evidence_values_check CHECK (
                    observed_chain_id IS NULL
                    OR (
                        observed_chain_id > 0
                        AND observed_chain_id = chain_id
                        AND tx_hash REGEXP BINARY '^0x[0-9a-f]{64}$'
                        AND confirmed_block_number > 0
                        AND confirmed_block_hash REGEXP BINARY '^0x[0-9a-f]{64}$'
                        AND payer_address REGEXP BINARY '^0x[0-9a-f]{40}$'
                        AND payer_address <> '0x0000000000000000000000000000000000000000'
                        AND reconciliation_status IN ('pending', 'verified', 'anomaly', 'transient_failure')
                    )
                )
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE payments
                ADD CONSTRAINT payments_reconciliation_values_check CHECK (
                    reconciliation_status IS NULL
                    OR (
                        reconciliation_status IN ('pending', 'verified', 'anomaly', 'transient_failure')
                        AND (
                            reconciliation_error_code IS NULL
                            OR reconciliation_error_code REGEXP BINARY '^[a-z0-9_]{1,64}$'
                        )
                    )
                )
            SQL);
        }
    }

    public function down(): void
    {
        // Confirmation evidence is audit data and is intentionally retained.
    }
};
