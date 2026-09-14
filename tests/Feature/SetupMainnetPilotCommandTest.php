<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\Store;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class SetupMainnetPilotCommandTest extends TestCase
{
    use RefreshDatabase;

    private const MERCHANT = '0xAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    private const SENDER = '0xBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'mainnet-staging';
        config([
            'services.fee_delegation.mainnet_staging.database_identifier' => DB::connection()->getDatabaseName(),
            'services.fee_delegation.mainnet_staging.kairos_database_identifier' => 'livt_kairos_test',
        ]);
    }

    public function test_refuses_non_mainnet_staging_environment(): void
    {
        $this->app['env'] = 'testing';

        $this->artisan('mainnet:setup-pilot')
            ->expectsOutputToContain('APP_ENV must be mainnet-staging')
            ->assertFailed();

        $this->assertAllPilotTablesEmpty();
    }

    public function test_refuses_wrong_database_identifier(): void
    {
        config([
            'services.fee_delegation.mainnet_staging.database_identifier' => 'different_database',
        ]);

        $this->artisan('mainnet:setup-pilot')
            ->expectsOutputToContain('not the approved Mainnet staging identifier')
            ->assertFailed();

        $this->assertAllPilotTablesEmpty();
    }

    public function test_refuses_kairos_database(): void
    {
        config([
            'services.fee_delegation.mainnet_staging.kairos_database_identifier' => DB::connection()->getDatabaseName(),
        ]);

        $this->artisan('mainnet:setup-pilot')
            ->expectsOutputToContain('not isolated from Kairos')
            ->assertFailed();

        $this->assertAllPilotTablesEmpty();
    }

    public function test_refuses_non_empty_database(): void
    {
        Store::query()->create([
            'store_code' => 'existing-store',
            'store_pin' => Hash::make('not-a-pilot-credential'),
            'name' => 'Existing Store',
        ]);

        $this->artisan('mainnet:setup-pilot')
            ->expectsOutputToContain('must exist and be empty')
            ->assertFailed();

        $this->assertDatabaseCount('stores', 1);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('staffs', 0);
        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_rejects_invalid_or_identical_addresses(): void
    {
        $this->pilotCommand(merchant: '0x1234')
            ->expectsOutputToContain('input is invalid')
            ->assertFailed();
        $this->assertAllPilotTablesEmpty();

        $this->pilotCommand(sender: self::MERCHANT)
            ->expectsOutputToContain('input is invalid')
            ->assertFailed();
        $this->assertAllPilotTablesEmpty();
    }

    public function test_success_creates_exact_canonical_records_without_payment(): void
    {
        $this->pilotCommand()
            ->expectsOutputToContain('Mainnet staging pilot identities created')
            ->expectsOutputToContain(
                'MAINNET_STAGING_MERCHANT_ADDRESS='.
                strtolower(self::MERCHANT)
            )
            ->expectsOutputToContain(
                'MAINNET_STAGING_APPROVED_SENDER_ADDRESSES='.
                strtolower(self::SENDER)
            )
            ->assertSuccessful();

        $this->assertDatabaseCount('stores', 1);
        $this->assertDatabaseCount('staffs', 1);
        $this->assertDatabaseCount('wallets', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('payments', 0);

        $store = Store::query()->sole();
        $staff = Staff::query()->sole();
        $wallet = Wallet::query()->sole();
        $user = User::query()->sole();

        $this->assertSame($store->id, $staff->store_id);
        $this->assertSame($store->id, $wallet->store_id);
        $this->assertSame('mainnet-pilot', $staff->staff_id);
        $this->assertSame('kaia-mainnet', $wallet->network);
        $this->assertSame(strtolower(self::MERCHANT), $wallet->address);
        $this->assertSame(strtolower(self::SENDER), $user->wallet_address);

        foreach (['1234', 'password', 'pilot-pin'] as $weakCredential) {
            $this->assertFalse(Hash::check($weakCredential, $store->store_pin));
            $this->assertFalse(Hash::check($weakCredential, $staff->pin));
            $this->assertFalse(Hash::check($weakCredential, $user->password));
        }
        $this->assertNotSame($store->store_pin, $staff->pin);
        $this->assertNotSame($staff->pin, $user->password);
    }

    public function test_credentials_are_distinct_cryptographically_random_values(): void
    {
        $plainCredentials = [];
        Hash::shouldReceive('make')
            ->times(3)
            ->andReturnUsing(function (string $credential) use (&$plainCredentials): string {
                $plainCredentials[] = $credential;

                return password_hash($credential, PASSWORD_BCRYPT);
            });

        $this->pilotCommand()->assertSuccessful();

        $this->assertCount(3, array_unique($plainCredentials));
        foreach ($plainCredentials as $credential) {
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $credential);
            $this->assertNotContains($credential, ['1234', 'password', 'pilot-pin']);
        }
    }

    public function test_all_writes_roll_back_when_late_insert_fails(): void
    {
        User::creating(function (): never {
            throw new \RuntimeException('forced pilot test failure');
        });

        $this->pilotCommand()
            ->expectsOutputToContain('no records were committed')
            ->assertFailed();

        $this->assertAllPilotTablesEmpty();
    }

    private function pilotCommand(
        string $merchant = self::MERCHANT,
        string $sender = self::SENDER
    ): mixed {
        return $this->artisan('mainnet:setup-pilot')
            ->expectsQuestion('Store name', 'Mainnet Pilot Store')
            ->expectsQuestion('Store code', 'mainnet-pilot-store')
            ->expectsQuestion(
                'Merchant / store JPYC receiving address',
                $merchant
            )
            ->expectsQuestion('Pilot user name', 'Mainnet Pilot User')
            ->expectsQuestion('Pilot user email', 'pilot@example.test')
            ->expectsQuestion('Approved sender wallet address', $sender);
    }

    private function assertAllPilotTablesEmpty(): void
    {
        foreach (['stores', 'staffs', 'wallets', 'users', 'payments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
