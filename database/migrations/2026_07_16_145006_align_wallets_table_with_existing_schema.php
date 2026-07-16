<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            if (!Schema::hasColumn('wallets', 'store_id')) {
                $table->foreignId('store_id')
                    ->nullable()
                    ->constrained('stores');
            }

            if (!Schema::hasColumn('wallets', 'address')) {
                $table->string('address')->nullable();
            }

            if (!Schema::hasColumn('wallets', 'network')) {
                $table->string('network', 50)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropColumn(['store_id', 'address', 'network']);
        });
    }
};