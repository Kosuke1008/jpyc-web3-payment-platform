<?php

namespace Tests\Feature;

use App\Blockchain\NetworkProfileRegistry;
use App\Models\Payment;
use App\Models\PaymentFeeDelegationAttempt;
use App\Models\Staff;
use App\Models\Store;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\PaymentSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class RotateMainnetPilotSenderTest extends TestCase
{
    use RefreshDatabase;

    private const MERCHANT = '0x1111111111111111111111111111111111111111';

    private const FEE_PAYER = '0x2222222222222222222222222222222222222222';

    private const OLD_SENDER = '0x3333333333333333333333333333333333333333';

    private const NEW_SENDER = '0xAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    private User $user;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'mainnet-staging';
        config([
            'blockchain.network' => 'kaia-mainnet',
            'blockchain.payments_mainnet_enabled' => false,
            'blockchain.mainnet_fee_delegation_enabled' => false,
            'blockchain.mainnet_broadcast_enabled' => false,
            'services.fee_delegation.mode' => 'self-hosted',
            'services.fee_delegation.self_hosted_mainnet.enabled' => false,
            'services.fee_delegation.self_hosted_mainnet.kill_switch' => true,
        ]);

        $store = Store::query()->create([
            'store_code' => 'sender-rotation-pilot',
            'store_pin' => 'test-only-store-hash',
            'name' => 'Sender Rotation Pilot',
        ]);
        $staff = Staff::query()->create([
            'store_id' => $store->id,
            'staff_id' => 'sender-rotation-staff',
            'name' => 'Sender Rotation Staff',
            'pin' => 'test-only-staff-hash',
            'role' => 'staff',
        ]);
        $this->user = User::query()->create([
            'name' => 'Sender Rotation User',
            'email' => 'sender-rotation@example.test',
            'password' => 'test-only-user-hash',
            'wallet_address' => self::OLD_SENDER,
        ]);
        Wallet::query()->create([
            'store_id' => $store->id,
            'address' => self::MERCHANT,
            'network' => 'kaia-mainnet',
        ]);
        config(['services.fee_delegation.mainnet_staging' => [
            'database_identifier' => DB::connection()->getDatabaseName(),
            'kairos_database_identifier' => 'different_kairos_database',
            'merchant_address' => self::MERCHANT,
            'fee_payer_address' => self::FEE_PAYER,
            'kairos_merchant_address' => '0x4444444444444444444444444444444444444444',
            'kairos_fee_payer_address' => '0x5555555555555555555555555555555555555555',
            'approved_user_ids' => (string) $this->user->id,
            'approved_sender_addresses' => self::OLD_SENDER,
            'allow_cross_environment_reuse' => false,
            'fee_payer_health_url' => 'http://127.0.0.1:19000/health',
        ]]);

        $snapshot = PaymentSnapshot::create(
            app(NetworkProfileRegistry::class)->get('kaia-mainnet'),
            self::MERCHANT,
            '1',
            now()->addHour(),
            1
        );
        $this->payment = Payment::query()->create(array_merge([
            'store_id' => $store->id,
            'staff_id' => $staff->id,
            'amount' => 1,
            'status' => 'pending',
        ], $snapshot->databaseAttributes()));
        Http::preventStrayRequests();
        $this->fakeClosedFeePayer();
    }

    public function test_success_updates_only_user_wallet_address_and_preserves_every_other_record(): void
    {
        $before = $this->rows();

        $this->rotation()
            ->expectsOutput('user_id='.$this->user->id)
            ->expectsOutput('old_sender_address='.self::OLD_SENDER)
            ->expectsOutput('new_sender_address='.strtolower(self::NEW_SENDER))
            ->expectsOutputToContain('MAINNET_STAGING_APPROVED_SENDER_ADDRESSES')
            ->assertSuccessful();

        $after = $this->rows();
        $this->assertSame(strtolower(self::NEW_SENDER), $after['users'][0]['wallet_address']);
        $after['users'][0]['wallet_address'] = self::OLD_SENDER;
        $this->assertSame($before, $after);
        $this->assertSame(self::OLD_SENDER, config('services.fee_delegation.mainnet_staging.approved_sender_addresses'));
        $this->assertDatabaseCount('payment_fee_delegation_attempts', 0);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public function test_http_503_with_closed_gates_and_safe_read_only_body_allows_rotation(): void
    {
        $this->fakeClosedFeePayer([], 503);

        $this->rotation()->assertSuccessful();

        $this->assertSame(strtolower(self::NEW_SENDER), $this->user->fresh()->wallet_address);
        Http::assertSentCount(2);
    }

    public function test_http_500_or_invalid_json_is_refused(): void
    {
        $this->fakeClosedFeePayer([], 500);
        $this->assertRefused();

        Http::fake(['127.0.0.1:19000/health' => Http::response('not-json', 503)]);
        $this->assertRefused();
    }

    public function test_http_503_with_any_remote_execution_gate_open_is_refused(): void
    {
        foreach (['execution', 'signing', 'broadcast'] as $gate) {
            $this->fakeClosedFeePayer([$gate => 'ENABLED'], 503);
            $this->assertRefused();
        }
    }

    public function test_wrong_environment_or_database_is_refused(): void
    {
        $original = $this->app->environment();
        $this->app['env'] = 'production';
        $this->assertRefused();
        $this->app['env'] = $original;

        config(['services.fee_delegation.mainnet_staging.database_identifier' => 'wrong_database']);
        $this->assertRefused();
        config(['services.fee_delegation.mainnet_staging.database_identifier' => DB::connection()->getDatabaseName()]);
        config(['services.fee_delegation.mainnet_staging.kairos_database_identifier' => DB::connection()->getDatabaseName()]);
        $this->assertRefused();
    }

    public function test_current_input_and_database_sender_must_exactly_match_configuration(): void
    {
        $this->assertRefused(current: '0x6666666666666666666666666666666666666666');

        $this->user->update(['wallet_address' => '0x6666666666666666666666666666666666666666']);
        $this->assertRefused();
        $this->user->update(['wallet_address' => self::OLD_SENDER]);

        config(['services.fee_delegation.mainnet_staging.approved_sender_addresses' => strtoupper(self::OLD_SENDER)]);
        $this->assertRefused();
    }

    public function test_existing_attempt_blocks_rotation(): void
    {
        PaymentFeeDelegationAttempt::query()->create([
            'payment_id' => $this->payment->id,
            'requester_user_id' => $this->user->id,
            'network' => 'kaia-mainnet',
            'chain_id' => 8217,
            'provider' => 'self-hosted',
            'sender_address' => self::OLD_SENDER,
            'sender_tx_hash' => '0x'.str_repeat('a', 64),
            'request_fingerprint' => str_repeat('b', 64),
            'state' => 'unknown_submission',
            'sender_nonce' => '1',
        ]);

        $this->assertRefused();
    }

    public function test_payment_transaction_or_confirmation_evidence_blocks_rotation(): void
    {
        foreach (['tx_hash', 'paid_at', 'reconciliation_status'] as $field) {
            $value = match ($field) {
                'tx_hash' => '0x'.str_repeat('a', 64),
                'paid_at' => now(),
                default => 'pending',
            };
            DB::table('payments')->where('id', $this->payment->id)->update([$field => $value]);
            $this->assertRefused();
            DB::table('payments')->where('id', $this->payment->id)->update([$field => null]);
        }
    }

    public function test_new_sender_must_be_distinct_valid_and_not_reuse_kairos_identity(): void
    {
        foreach ([
            self::OLD_SENDER,
            self::MERCHANT,
            self::FEE_PAYER,
            '0x4444444444444444444444444444444444444444',
            '0x5555555555555555555555555555555555555555',
            '0x1234',
        ] as $new) {
            $this->assertRefused(new: $new);
        }
    }

    public function test_open_gates_or_unverified_fee_payer_are_refused(): void
    {
        foreach ([
            'blockchain.payments_mainnet_enabled',
            'blockchain.mainnet_fee_delegation_enabled',
            'blockchain.mainnet_broadcast_enabled',
            'services.fee_delegation.self_hosted_mainnet.enabled',
        ] as $gate) {
            config([$gate => true]);
            $this->assertRefused();
            config([$gate => false]);
        }
        config(['services.fee_delegation.self_hosted_mainnet.kill_switch' => false]);
        $this->assertRefused();
        config(['services.fee_delegation.self_hosted_mainnet.kill_switch' => true]);

        $this->fakeClosedFeePayer(['signing' => 'ENABLED']);
        $this->assertRefused();
        $this->fakeClosedFeePayer();
        config(['services.fee_delegation.mainnet_staging.fee_payer_health_url' => 'http://localhost:19000/health']);
        $this->assertRefused();
    }

    public function test_wrong_network_chain_mode_or_identity_cardinality_is_refused(): void
    {
        config(['blockchain.network' => 'kairos']);
        $this->assertRefused();
        config(['blockchain.network' => 'kaia-mainnet']);

        config(['blockchain.profiles.kaia-mainnet.chain_id' => 1001]);
        $this->assertRefused();
        config(['blockchain.profiles.kaia-mainnet.chain_id' => 8217]);

        config(['services.fee_delegation.mode' => 'kaia-managed']);
        $this->assertRefused();
        config(['services.fee_delegation.mode' => 'self-hosted']);

        Store::query()->create([
            'store_code' => 'unexpected-second-store',
            'store_pin' => 'test-only-other-hash',
            'name' => 'Unexpected Store',
        ]);
        $this->assertRefused();
    }

    public function test_command_has_no_environment_write_or_transaction_submission_path(): void
    {
        $source = file_get_contents(app_path('Console/Commands/RotateMainnetPilotSender.php'));
        $this->assertIsString($source);
        foreach (['file_put_contents', 'fwrite(', 'Dotenv', 'sendRawTransaction', 'signAsFeePayer'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    public function test_late_failure_rolls_back_wallet_address_and_preserves_all_rows(): void
    {
        $before = $this->rows();
        User::updated(function (): never {
            throw new RuntimeException('forced late failure');
        });

        $this->rotation()->assertFailed();

        $this->assertSame($before, $this->rows());
        $this->assertDatabaseCount('payment_fee_delegation_attempts', 0);
        Http::assertSentCount(2);
    }

    private function assertRefused(string $current = self::OLD_SENDER, string $new = self::NEW_SENDER): void
    {
        $before = $this->rows();
        $command = $current !== self::OLD_SENDER || $new !== self::NEW_SENDER
            ? $this->rotation($current, $new)
            : $this->artisan('mainnet:rotate-pilot-sender');
        $command->assertFailed();
        $this->assertSame($before, $this->rows());
    }

    private function rotation(string $current = self::OLD_SENDER, string $new = self::NEW_SENDER): mixed
    {
        return $this->artisan('mainnet:rotate-pilot-sender')
            ->expectsQuestion('Current approved sender address', $current)
            ->expectsQuestion('New approved sender address', $new);
    }

    /** @param array<string, mixed> $overrides */
    private function fakeClosedFeePayer(array $overrides = [], int $httpStatus = 200): void
    {
        Http::fake(['127.0.0.1:19000/health' => Http::response(array_merge([
            'status' => 'READ_ONLY_READY',
            'network' => 'kaia-mainnet',
            'chain_id' => 8217,
            'execution' => 'DISABLED',
            'signing' => 'DISABLED',
            'broadcast' => 'DISABLED',
            'kill_switch' => 'ACTIVE',
        ], $overrides), $httpStatus)]);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function rows(): array
    {
        $rows = [];
        foreach (['stores', 'staffs', 'wallets', 'users', 'payments'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()
                ->map(fn (object $record): array => (array) $record)->all();
        }

        return $rows;
    }
}
