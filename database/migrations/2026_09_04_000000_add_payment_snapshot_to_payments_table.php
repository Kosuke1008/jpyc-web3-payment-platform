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
            $network = $table->string('network', 32)->nullable()->after('expires_at');
            $table->unsignedSmallInteger('network_profile_version')->nullable()->after('network');
            $table->unsignedBigInteger('chain_id')->nullable()->after('network_profile_version');
            $tokenContract = $table->char('token_contract', 42)->nullable()->after('chain_id');
            $tokenSymbol = $table->string('token_symbol', 16)->nullable()->after('token_contract');
            $table->unsignedTinyInteger('token_decimals')->nullable()->after('token_symbol');
            $recipient = $table->char('recipient_address', 42)->nullable()->after('token_decimals');
            $table->unsignedBigInteger('display_amount')->nullable()->after('recipient_address');
            $atomic = $table->string('atomic_amount', 78)->nullable()->after('display_amount');
            $table->index(
                ['chain_id', 'tx_hash'],
                'payments_chain_id_tx_hash_index'
            );

            if ($mysql) {
                foreach ([$network, $tokenContract, $tokenSymbol, $recipient, $atomic] as $column) {
                    $column->charset('ascii')->collation('ascii_bin');
                }
            }
        });

        if ($mysql) {
            $this->addMySqlChecks();
        }
    }

    public function down(): void
    {
        // Snapshot data is payment audit data. Production rollback must leave
        // these additive columns intact; a destructive removal is intentionally
        // not automated.
    }

    private function addMySqlChecks(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE payments
            ADD CONSTRAINT payments_snapshot_presence_check CHECK (
                (
                    network IS NULL
                    AND network_profile_version IS NULL
                    AND chain_id IS NULL
                    AND token_contract IS NULL
                    AND token_symbol IS NULL
                    AND token_decimals IS NULL
                    AND recipient_address IS NULL
                    AND display_amount IS NULL
                    AND atomic_amount IS NULL
                )
                OR
                (
                    network IS NOT NULL
                    AND network_profile_version IS NOT NULL
                    AND chain_id IS NOT NULL
                    AND token_contract IS NOT NULL
                    AND token_symbol IS NOT NULL
                    AND token_decimals IS NOT NULL
                    AND recipient_address IS NOT NULL
                    AND display_amount IS NOT NULL
                    AND atomic_amount IS NOT NULL
                    AND expires_at IS NOT NULL
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE payments
            ADD CONSTRAINT payments_snapshot_values_check CHECK (
                network IS NULL
                OR (
                    network IN ('kairos', 'kaia-mainnet', 'sepolia')
                    AND network_profile_version > 0
                    AND chain_id > 0
                    AND token_contract REGEXP BINARY '^0x[0-9a-f]{40}$'
                    AND token_symbol REGEXP BINARY '^[A-Z0-9]{1,16}$'
                    AND token_decimals BETWEEN 0 AND 255
                    AND recipient_address REGEXP BINARY '^0x[0-9a-f]{40}$'
                    AND display_amount BETWEEN 1 AND 100000
                    AND atomic_amount REGEXP BINARY '^[1-9][0-9]{0,77}$'
                )
            )
        SQL);
    }
};
