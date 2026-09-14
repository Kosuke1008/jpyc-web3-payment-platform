<?php

namespace App\Payments;

use App\Blockchain\MainnetReadinessChecker;
use App\Blockchain\NetworkProfile;
use App\Blockchain\NetworkProfileRegistry;
use App\Models\Staff;
use App\Models\Store;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class MainnetPilotGuard
{
    public function __construct(private readonly NetworkProfileRegistry $networks) {}

    public function assertDatabase(): void
    {
        if (! app()->environment('mainnet-staging')) {
            throw new RuntimeException('environment_not_mainnet_staging');
        }
        $expected = config('services.fee_delegation.mainnet_staging.database_identifier');
        $kairos = config('services.fee_delegation.mainnet_staging.kairos_database_identifier');
        $actual = DB::getDriverName() === 'mysql'
            ? DB::selectOne('SELECT DATABASE() AS database_name')?->database_name
            : DB::connection()->getDatabaseName();
        if (! is_string($expected) || $expected === '' || $actual !== $expected) {
            throw new RuntimeException('database_identifier_mismatch');
        }
        if (! is_string($kairos) || $kairos === '' || $actual === $kairos) {
            throw new RuntimeException('kairos_database_rejected');
        }
    }

    public function profile(): NetworkProfile
    {
        $profile = $this->networks->active();
        if ($profile->id !== 'kaia-mainnet' || $profile->chainId !== 8217
            || $profile->jpycContract !== MainnetReadinessChecker::JPYC_CONTRACT
            || $profile->jpycSymbol !== 'JPYC' || $profile->jpycDecimals !== 18
            || $profile->testnet
            || config('blockchain.payments_mainnet_enabled') !== false
            || config('blockchain.mainnet_fee_delegation_enabled') !== false
            || config('blockchain.mainnet_broadcast_enabled') !== false
            || config('services.fee_delegation.mode') !== 'self-hosted'
            || config('services.fee_delegation.self_hosted_mainnet.enabled') !== false
            || config('services.fee_delegation.self_hosted_mainnet.kill_switch') !== true) {
            throw new RuntimeException('mainnet_profile_or_disabled_gates_invalid');
        }

        return $profile;
    }

    /** @return array{store: Store, staff: Staff, user: User, merchant: string, sender: string, fee_payer: string} */
    public function identities(bool $lock = false): array
    {
        $config = config('services.fee_delegation.mainnet_staging', []);
        $userId = self::positiveId($config['approved_user_ids'] ?? null);
        $merchant = self::address($config['merchant_address'] ?? null);
        $sender = self::address($config['approved_sender_addresses'] ?? null);
        $feePayer = self::address($config['fee_payer_address'] ?? null);
        $kairosMerchant = self::address($config['kairos_merchant_address'] ?? null);
        $kairosPayer = self::address($config['kairos_fee_payer_address'] ?? null);
        if ($userId === null || $merchant === null || $sender === null || $feePayer === null
            || $kairosMerchant === null || $kairosPayer === null
            || ($config['allow_cross_environment_reuse'] ?? null) !== false
            || count(array_unique([$merchant, $sender, $feePayer])) !== 3
            || array_intersect([$merchant, $sender, $feePayer], [$kairosMerchant, $kairosPayer]) !== []) {
            throw new RuntimeException('pilot_identity_configuration_invalid');
        }
        // The dedicated pilot database has exactly the identity set created by setup-pilot.
        $store = Store::query()->when($lock, fn ($query) => $query->lockForUpdate())->sole();
        $staff = Staff::query()->when($lock, fn ($query) => $query->lockForUpdate())->sole();
        $wallet = Wallet::query()->when($lock, fn ($query) => $query->lockForUpdate())->sole();
        $user = User::query()->when($lock, fn ($query) => $query->lockForUpdate())->sole();
        if ($user->id !== $userId || self::address($user->wallet_address) !== $sender
            || $staff->store_id !== $store->id || ! $staff->is_active
            || $wallet->store_id !== $store->id || $wallet->network !== 'kaia-mainnet'
            || self::address($wallet->address) !== $merchant) {
            throw new RuntimeException('pilot_database_identity_mismatch');
        }

        return ['store' => $store, 'staff' => $staff, 'user' => $user,
            'merchant' => $merchant, 'sender' => $sender, 'fee_payer' => $feePayer];
    }

    public function transaction(callable $operation): mixed
    {
        $this->assertDatabase();
        $mysql = DB::getDriverName() === 'mysql';
        if ($mysql) {
            $tables = DB::select('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?, ?, ?, ?, ?)',
                ['stores', 'staffs', 'users', 'wallets', 'payments', 'payment_fee_delegation_attempts']);
            if (count($tables) !== 6 || array_filter($tables, fn ($table) => $table->ENGINE !== 'InnoDB')) {
                throw new RuntimeException('transactional_tables_required');
            }
            if ((int) (DB::selectOne("SELECT GET_LOCK('livt_mainnet_pilot_setup', 0) AS acquired")->acquired ?? 0) !== 1) {
                throw new RuntimeException('pilot_setup_lock_unavailable');
            }
        }
        try {
            return DB::transaction(function () use ($operation) {
                $this->assertDatabase();

                return $operation();
            }, 1);
        } finally {
            if ($mysql) {
                try {
                    DB::selectOne("SELECT RELEASE_LOCK('livt_mainnet_pilot_setup')");
                } catch (Throwable) {
                    // Connection teardown releases the lock; never retry committed work.
                }
            }
        }
    }

    public static function positiveId(mixed $value): ?int
    {
        if ((! is_int($value) && ! is_string($value))
            || preg_match('/\A[1-9][0-9]*\z/', (string) $value) !== 1) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($id) ? $id : null;
    }

    public static function address(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A0x[0-9a-fA-F]{40}\z/', $value) === 1
            && strtolower($value) !== '0x'.str_repeat('0', 40) ? strtolower($value) : null;
    }
}
