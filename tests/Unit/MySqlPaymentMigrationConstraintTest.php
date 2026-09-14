<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MySqlPaymentMigrationConstraintTest extends TestCase
{
    public function test_mysql_payment_constraints_do_not_use_binary_regexp_casts(): void
    {
        $migrations = glob(dirname(__DIR__, 2).'/database/migrations/*.php');
        $this->assertIsArray($migrations);

        foreach ($migrations as $migration) {
            $source = file_get_contents($migration);
            $this->assertIsString($source);
            $this->assertStringNotContainsString(
                'REGEXP BINARY',
                $source,
                basename($migration)
            );
        }
    }

    public function test_snapshot_patterns_remain_case_sensitive_under_ascii_bin(): void
    {
        $source = $this->migration(
            '2026_09_04_000000_add_payment_snapshot_to_payments_table.php'
        );

        $this->assertStringContainsString(
            "->charset('ascii')->collation('ascii_bin')",
            $source
        );
        $this->assertStringContainsString(
            "token_contract REGEXP '^0x[0-9a-f]{40}$'",
            $source
        );
        $this->assertStringContainsString(
            "token_symbol REGEXP '^[A-Z0-9]{1,16}$'",
            $source
        );
        $this->assertStringContainsString(
            "recipient_address REGEXP '^0x[0-9a-f]{40}$'",
            $source
        );
        $this->assertStringContainsString(
            "atomic_amount REGEXP '^[1-9][0-9]{0,77}$'",
            $source
        );
    }

    public function test_legacy_tx_hash_uses_explicit_case_sensitive_regexp_mode(): void
    {
        $source = $this->migration(
            '2026_09_06_000000_add_confirmation_evidence_to_payments_table.php'
        );

        $this->assertStringContainsString(
            "REGEXP_LIKE(tx_hash, '^0x[0-9a-f]{64}$', 'c')",
            $source
        );
    }

    private function migration(string $name): string
    {
        $source = file_get_contents(
            dirname(__DIR__, 2)."/database/migrations/{$name}"
        );
        $this->assertIsString($source);

        return $source;
    }
}
