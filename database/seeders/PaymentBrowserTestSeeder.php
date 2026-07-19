<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class PaymentBrowserTestSeeder extends Seeder
{
    public const STORE_ID = 910001;

    public const STAFF_ID = 910001;

    public const WALLET_ID = 910001;

    public const USER_ID = 910001;

    public const HAPPY_PAYMENT_ID = 910001;

    public const RECOVERY_PAYMENT_ID = 910002;

    public const EXPIRED_PAYMENT_ID = 910003;

    public const PAYMENT_AMOUNT = 125;

    public const RECIPIENT_ADDRESS = '0x70997970c51812dc3a010c7d01b50e0d17dc79c8';

    public const USER_EMAIL = 'browser-payment-e2e@example.test';

    public function run(): void
    {
        $databaseName = DB::connection()->getDatabaseName();

        if (! app()->environment('e2e')
            || ! str_contains(strtolower($databaseName), 'e2e')) {
            throw new RuntimeException(
                'Payment browser fixtures require an isolated E2E database.'
            );
        }

        $userPassword = getenv('LIVT_E2E_USER_PASSWORD');

        if (! is_string($userPassword) || strlen($userPassword) < 16) {
            throw new RuntimeException(
                'The payment browser user password is unavailable.'
            );
        }

        $now = now();

        DB::transaction(function () use ($now, $userPassword): void {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', 'App\\Models\\User')
                ->where('tokenable_id', self::USER_ID)
                ->delete();

            DB::table('stores')->updateOrInsert(
                ['id' => self::STORE_ID],
                [
                    'store_code' => 'browser-payment-e2e-store',
                    'store_pin' => Hash::make(bin2hex(random_bytes(16))),
                    'name' => 'Browser Review Store',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            DB::table('staffs')->updateOrInsert(
                ['id' => self::STAFF_ID],
                [
                    'store_id' => self::STORE_ID,
                    'staff_id' => 'browser-payment-e2e-staff',
                    'name' => 'Browser Review Staff',
                    'pin' => Hash::make(bin2hex(random_bytes(16))),
                    'role' => 'staff',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            DB::table('wallets')->updateOrInsert(
                ['id' => self::WALLET_ID],
                [
                    'store_id' => self::STORE_ID,
                    'address' => self::RECIPIENT_ADDRESS,
                    'network' => 'kairos',
                    'balance' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            DB::table('users')->updateOrInsert(
                ['id' => self::USER_ID],
                [
                    'name' => 'Browser Review User',
                    'email' => self::USER_EMAIL,
                    'password' => Hash::make($userPassword),
                    'wallet_address' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $this->upsertPayment(
                self::HAPPY_PAYMENT_ID,
                $now->copy()->addMinutes(30),
                $now
            );
            $this->upsertPayment(
                self::RECOVERY_PAYMENT_ID,
                $now->copy()->addMinutes(30),
                $now
            );
            $this->upsertPayment(
                self::EXPIRED_PAYMENT_ID,
                $now->copy()->subMinute(),
                $now
            );
        });
    }

    private function upsertPayment(int $paymentId, mixed $expiresAt, mixed $now): void
    {
        DB::table('payments')->updateOrInsert(
            ['id' => $paymentId],
            [
                'store_id' => self::STORE_ID,
                'staff_id' => self::STAFF_ID,
                'user_id' => null,
                'amount' => self::PAYMENT_AMOUNT,
                'status' => 'pending',
                'tx_hash' => null,
                'paid_at' => null,
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }
}
