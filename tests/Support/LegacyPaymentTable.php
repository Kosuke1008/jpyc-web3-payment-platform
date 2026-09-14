<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Shadows the hardened table for tests of historical, pre-hardening rows.
 * MySQL TEMPORARY tables are connection-local; the persistent schema is untouched.
 */
final class LegacyPaymentTable
{
    public static function create(bool $uniqueTransactionHash = true): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $columns = DB::select('SHOW COLUMNS FROM payments');
        $definitions = [];
        foreach ($columns as $column) {
            $name = $column->Field;
            if (! is_string($name) || preg_match('/\A[a-z_][a-z0-9_]*\z/', $name) !== 1) {
                throw new RuntimeException('Unexpected Payment column name.');
            }

            $definitions[] = $name === 'id'
                ? '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'
                : "`{$name}` {$column->Type} NULL";
        }

        if ($uniqueTransactionHash) {
            $definitions[] = 'UNIQUE KEY `payments_chain_id_tx_hash_unique` (`chain_id`, `tx_hash`)';
        }

        DB::statement('CREATE TEMPORARY TABLE `payments` ('.implode(', ', $definitions).')');
    }

    public static function drop(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('DROP TEMPORARY TABLE IF EXISTS `payments`');
        }
    }
}
