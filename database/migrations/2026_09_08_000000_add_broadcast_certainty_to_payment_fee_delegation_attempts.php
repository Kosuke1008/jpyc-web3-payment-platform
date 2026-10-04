<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_fee_delegation_attempts', function (Blueprint $table) {
            // Existing rows remain null and are therefore never considered safe
            // evidence that a transaction was definitely not broadcast.
            $table->string('broadcast_certainty', 32)
                ->nullable()
                ->after('diagnostic_code');
        });
    }

    public function down(): void
    {
        Schema::table('payment_fee_delegation_attempts', function (Blueprint $table) {
            $table->dropColumn('broadcast_certainty');
        });
    }
};
