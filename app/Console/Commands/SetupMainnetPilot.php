<?php

namespace App\Console\Commands;

use App\Models\Staff;
use App\Models\Store;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class SetupMainnetPilot extends Command
{
    private const REQUIRED_EMPTY_TABLES = [
        'users',
        'stores',
        'staffs',
        'wallets',
        'payments',
    ];

    protected $signature = 'mainnet:setup-pilot';

    protected $description = 'Create one guarded Mainnet staging pilot identity set without a Payment';

    public function handle(): int
    {
        try {
            $this->assertSafeTarget();
        } catch (Throwable $exception) {
            $this->error($this->safeGuardMessage($exception));

            return self::FAILURE;
        }

        $input = $this->promptForInput();
        if ($input === null) {
            return self::FAILURE;
        }

        $credentials = [
            'store_pin' => $this->generateCredential(),
            'staff_pin' => $this->generateCredential(),
            'user_password' => $this->generateCredential(),
        ];

        $lockAcquired = false;
        try {
            $lockAcquired = $this->acquireSetupLock();
            if (! $lockAcquired) {
                throw new RuntimeException('setup_lock_unavailable');
            }

            $records = DB::transaction(function () use (
                $input,
                $credentials
            ): array {
                $this->assertSafeTarget();

                $store = Store::query()->create([
                    'store_code' => $input['store_code'],
                    'store_pin' => Hash::make($credentials['store_pin']),
                    'name' => $input['store_name'],
                ]);
                $staff = Staff::query()->create([
                    'store_id' => $store->id,
                    'staff_id' => 'mainnet-pilot',
                    'name' => 'Mainnet Pilot Staff',
                    'pin' => Hash::make($credentials['staff_pin']),
                    'role' => 'manager',
                ]);
                Wallet::query()->create([
                    'store_id' => $store->id,
                    'address' => $input['merchant_address'],
                    'network' => 'kaia-mainnet',
                ]);
                $user = User::query()->create([
                    'name' => $input['user_name'],
                    'email' => $input['user_email'],
                    'password' => Hash::make($credentials['user_password']),
                    'wallet_address' => $input['sender_address'],
                ]);

                return compact('store', 'staff', 'user');
            });
        } catch (Throwable) {
            $this->error(
                'Pilot setup failed safely; no records were committed.'
            );

            return self::FAILURE;
        } finally {
            if ($lockAcquired) {
                try {
                    $this->releaseSetupLock();
                } catch (Throwable) {
                    // A lost MySQL connection releases its advisory locks automatically.
                }
            }
        }

        $this->info('Mainnet staging pilot identities created.');
        $this->line('store_id='.$records['store']->id);
        $this->line('staff_id='.$records['staff']->id);
        $this->line('user_id='.$records['user']->id);
        $this->line('store_code='.$input['store_code']);
        $this->line('merchant_address='.$input['merchant_address']);
        $this->line('approved_sender_address='.$input['sender_address']);
        $this->newLine();
        $this->warn(
            'SECRETS (shown once): store securely and do not copy to logs.'
        );
        $this->line('store_pin='.$credentials['store_pin']);
        $this->line('staff_pin='.$credentials['staff_pin']);
        $this->line('user_password='.$credentials['user_password']);
        $this->newLine();
        $this->info('Configuration values to set later:');
        $this->line(
            'MAINNET_STAGING_MERCHANT_ADDRESS='.$input['merchant_address']
        );
        $this->line(
            'MAINNET_STAGING_APPROVED_USER_IDS='.$records['user']->id
        );
        $this->line(
            'MAINNET_STAGING_APPROVED_SENDER_ADDRESSES='.
            $input['sender_address']
        );

        return self::SUCCESS;
    }

    /** @return array<string, string>|null */
    private function promptForInput(): ?array
    {
        $input = [
            'store_name' => trim((string) $this->ask('Store name')),
            'store_code' => trim((string) $this->ask('Store code')),
            'merchant_address' => $this->address(
                $this->ask('Merchant / store JPYC receiving address')
            ),
            'user_name' => trim((string) $this->ask('Pilot user name')),
            'user_email' => strtolower(trim((string) $this->ask(
                'Pilot user email'
            ))),
            'sender_address' => $this->address(
                $this->ask('Approved sender wallet address')
            ),
        ];

        if ($input['store_name'] === ''
            || mb_strlen($input['store_name']) > 255
            || preg_match(
                '/\A[A-Za-z0-9][A-Za-z0-9_-]{2,63}\z/',
                $input['store_code']
            ) !== 1
            || $input['merchant_address'] === null
            || $input['user_name'] === ''
            || mb_strlen($input['user_name']) > 255
            || strlen($input['user_email']) > 255
            || filter_var($input['user_email'], FILTER_VALIDATE_EMAIL) === false
            || $input['sender_address'] === null
            || $input['merchant_address'] === $input['sender_address']) {
            $this->error('Pilot setup input is invalid. No records were created.');

            return null;
        }

        /** @var array<string, string> $input */
        return $input;
    }

    private function assertSafeTarget(): void
    {
        if (! app()->environment('mainnet-staging')) {
            throw new RuntimeException('environment_not_mainnet_staging');
        }

        $configuration = config('services.fee_delegation.mainnet_staging');
        $configuration = is_array($configuration) ? $configuration : [];
        $expected = $configuration['database_identifier'] ?? null;
        $kairos = $configuration['kairos_database_identifier'] ?? null;
        $actual = DB::connection()->getDatabaseName();

        if (! is_string($expected)
            || $expected === ''
            || ! is_string($actual)
            || $actual === ''
            || $actual !== $expected) {
            throw new RuntimeException('database_identifier_mismatch');
        }

        if (! is_string($kairos)
            || $kairos === ''
            || $actual === $kairos) {
            throw new RuntimeException('kairos_database_rejected');
        }

        foreach (self::REQUIRED_EMPTY_TABLES as $table) {
            if (! Schema::hasTable($table)
                || DB::table($table)->limit(1)->exists()) {
                throw new RuntimeException('database_not_empty');
            }
        }
    }

    private function safeGuardMessage(Throwable $exception): string
    {
        return match ($exception->getMessage()) {
            'environment_not_mainnet_staging' => 'Refusing: APP_ENV must be mainnet-staging.',
            'database_identifier_mismatch' => 'Refusing: the current database identifier is not the approved Mainnet staging identifier.',
            'kairos_database_rejected' => 'Refusing: the current database is not isolated from Kairos.',
            'database_not_empty' => 'Refusing: all pilot target tables must exist and be empty.',
            default => 'Refusing: Mainnet staging safety checks failed.',
        };
    }

    private function address(mixed $value): ?string
    {
        if (! is_string($value)
            || preg_match('/\A0x[0-9a-fA-F]{40}\z/', $value) !== 1) {
            return null;
        }

        $address = strtolower($value);

        return $address === '0x'.str_repeat('0', 40) ? null : $address;
    }

    private function generateCredential(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function acquireSetupLock(): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return true;
        }

        $result = DB::selectOne(
            "SELECT GET_LOCK('livt_mainnet_pilot_setup', 0) AS acquired"
        );

        return (int) ($result->acquired ?? 0) === 1;
    }

    private function releaseSetupLock(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::selectOne(
                "SELECT RELEASE_LOCK('livt_mainnet_pilot_setup')"
            );
        }
    }
}
