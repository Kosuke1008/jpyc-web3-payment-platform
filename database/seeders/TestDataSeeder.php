<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        DB::table('stores')->updateOrInsert(
            ['store_code' => 'STORE001'],
            [
                'store_pin' => Hash::make('1234'),
                'name' => 'LivT Cafe',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $storeId = DB::table('stores')
            ->where('store_code', 'STORE001')
            ->value('id');

        DB::table('staffs')->updateOrInsert(
            [
                'store_id' => $storeId,
                'staff_id' => '001',
            ],
            [
                'name' => 'Test Staff',
                'pin' => Hash::make('1234'),
                'role' => 'manager',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        DB::table('users')->updateOrInsert(
            ['email' => 'user@example.com'],
            [
                'name' => 'Test User',
                'password' => Hash::make('password'),
                'wallet_address' => '0x65B66fdeD7b7Ff2ab328De9E6964c79aDCd95Ac0',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        DB::table('wallets')->updateOrInsert(
            [
                'store_id' => $storeId,
                'network' => 'kairos',
            ],
            [
                'address' => '0x923bFce1ac4D318441700f26Ad4ECaF39522e32A',
                'balance' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }
}