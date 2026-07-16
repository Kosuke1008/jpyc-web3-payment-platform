<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payments', 'qr_payload')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('qr_payload');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('payments', 'qr_payload')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('qr_payload');
            });
        }
    }
};
