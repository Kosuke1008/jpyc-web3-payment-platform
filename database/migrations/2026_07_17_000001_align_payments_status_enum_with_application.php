<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->canRunStatusMigration()) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE payments
            MODIFY COLUMN status ENUM('pending', 'paid', 'canceled', 'confirmed', 'failed')
            NOT NULL DEFAULT 'pending'
        SQL);

        DB::table('payments')
            ->where('status', 'paid')
            ->update(['status' => 'confirmed']);

        DB::table('payments')
            ->where('status', 'canceled')
            ->update(['status' => 'failed']);

        DB::statement(<<<'SQL'
            ALTER TABLE payments
            MODIFY COLUMN status ENUM('pending', 'confirmed', 'failed')
            NOT NULL DEFAULT 'pending'
        SQL);
    }

    public function down(): void
    {
        if (! $this->canRunStatusMigration()) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE payments
            MODIFY COLUMN status ENUM('pending', 'paid', 'canceled', 'confirmed', 'failed')
            NOT NULL DEFAULT 'pending'
        SQL);

        DB::table('payments')
            ->where('status', 'confirmed')
            ->update(['status' => 'paid']);

        DB::table('payments')
            ->where('status', 'failed')
            ->update(['status' => 'canceled']);

        DB::statement(<<<'SQL'
            ALTER TABLE payments
            MODIFY COLUMN status ENUM('pending', 'paid', 'canceled')
            NOT NULL DEFAULT 'pending'
        SQL);
    }

    private function canRunStatusMigration(): bool
    {
        return DB::getDriverName() === 'mysql'
            && Schema::hasColumn('payments', 'status');
    }
};
