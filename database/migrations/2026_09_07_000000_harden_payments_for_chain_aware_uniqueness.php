<?php

use App\Payments\PaymentHardeningPreflight;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            if (! DB::connection()->pretending()) {
                app(PaymentHardeningPreflight::class)->assertSafe();
            }

            DB::statement(<<<'SQL'
                ALTER TABLE payments
                    DROP INDEX payments_tx_hash_unique,
                    DROP INDEX payments_chain_id_tx_hash_index,
                    MODIFY network VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                    MODIFY network_profile_version SMALLINT UNSIGNED NOT NULL,
                    MODIFY chain_id BIGINT UNSIGNED NOT NULL,
                    MODIFY token_contract CHAR(42) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                    MODIFY token_symbol VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                    MODIFY token_decimals TINYINT UNSIGNED NOT NULL,
                    MODIFY recipient_address CHAR(42) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                    MODIFY display_amount BIGINT UNSIGNED NOT NULL,
                    MODIFY atomic_amount VARCHAR(78) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                    MODIFY expires_at TIMESTAMP NOT NULL,
                    MODIFY tx_hash CHAR(66) CHARACTER SET ascii COLLATE ascii_bin NULL,
                    ADD UNIQUE INDEX payments_chain_id_tx_hash_unique (chain_id, tx_hash),
                    ADD CONSTRAINT payments_confirmed_evidence_check CHECK (
                        status <> 'confirmed'
                        OR (
                            tx_hash IS NOT NULL
                            AND observed_chain_id = chain_id
                            AND confirmed_block_number IS NOT NULL
                            AND confirmed_block_hash IS NOT NULL
                            AND receipt_status = 1
                            AND payer_address IS NOT NULL
                            AND transfer_log_index IS NOT NULL
                            AND chain_confirmed_at IS NOT NULL
                            AND verified_at IS NOT NULL
                        )
                    )
            SQL);

            return;
        }

        // SQLite is used by the test suite. Keep nullable legacy fixtures
        // representable while exercising the chain-aware unique index.
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_tx_hash_unique');
            $table->dropIndex('payments_chain_id_tx_hash_index');
            $table->unique(
                ['chain_id', 'tx_hash'],
                'payments_chain_id_tx_hash_unique'
            );
        });
    }

    public function down(): void
    {
        // Audit data and the chain boundary must not be weakened by an
        // automated production rollback.
    }
};
