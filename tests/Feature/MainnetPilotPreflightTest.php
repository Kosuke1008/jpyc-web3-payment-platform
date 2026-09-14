<?php

namespace Tests\Feature;

use App\Blockchain\MainnetPilotPreflightChecker;
use App\Blockchain\MainnetStagingInfrastructure;
use App\Blockchain\NetworkProfileRegistry;
use App\Models\Payment;
use App\Models\PaymentFeeDelegationAttempt;
use App\Models\Staff;
use App\Models\Store;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\MainnetPilotLimits;
use App\Payments\PaymentSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MainnetPilotPreflightTest extends TestCase
{
    use RefreshDatabase;

    private const PRIMARY = 'https://mainnet-primary.example.test';

    private const SECONDARY = 'https://mainnet-secondary.example.test';

    private const MERCHANT = '0x1111111111111111111111111111111111111111';

    private const FEE_PAYER = '0x2222222222222222222222222222222222222222';

    private const SENDER = '0x3333333333333333333333333333333333333333';

    private Payment $payment;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'mainnet-staging';

        [$this->payment, $this->user] = $this->createPilotPayment();
        config([
            'blockchain.network' => 'kaia-mainnet',
            'blockchain.profiles.kaia-mainnet.rpc_url' => self::PRIMARY,
            'blockchain.profiles.kaia-mainnet.secondary_rpc_url' => self::SECONDARY,
            'blockchain.profiles.kairos.rpc_url' => 'https://kairos.example.test',
            'blockchain.payments_mainnet_enabled' => false,
            'blockchain.mainnet_fee_delegation_enabled' => false,
            'blockchain.mainnet_broadcast_enabled' => false,
            'blockchain.mainnet_readiness_max_block_lag' => 10,
            'services.fee_delegation.mode' => 'self-hosted',
            'services.fee_delegation.max_gas' => 150000,
            'services.fee_delegation.self_hosted_mainnet' => [
                'enabled' => false,
                'kill_switch' => true,
                'max_payment_jpy' => 1,
                'max_gas' => 150000,
                'max_gas_price_wei' => '25000000000',
                'rate_window_seconds' => 3600,
                'max_attempts_per_user' => 1,
                'max_attempts_per_store' => 1,
                'max_attempts_per_sender' => 1,
                'max_attempts_global' => 1,
                'daily_transaction_limit' => 1,
                'daily_kaia_budget' => '0.00375',
                'minimum_reserve_kaia' => '0.01',
            ],
            'services.fee_delegation.mainnet_staging' => [
                'environment_id' => 'mainnet-staging',
                'database_identifier' => DB::connection()->getDatabaseName(),
                'kairos_database_identifier' => 'livt_kairos',
                'cache_prefix' => 'livt-mainnet-staging-cache-',
                'kairos_cache_prefix' => 'livt-kairos-cache-',
                'merchant_address' => self::MERCHANT,
                'kairos_merchant_address' => '0x4444444444444444444444444444444444444444',
                'fee_payer_address' => self::FEE_PAYER,
                'kairos_fee_payer_address' => '0x5555555555555555555555555555555555555555',
                'approved_user_ids' => (string) $this->user->id,
                'approved_sender_addresses' => self::SENDER,
                'fee_payer_health_url' => 'http://127.0.0.1:19000/health',
                'stage2_legacy_policy_resolved' => false,
                'allow_cross_environment_reuse' => false,
                'pilot_payment_id' => $this->payment->id,
                'pilot_max_fee_payer_balance_kaia' => '0.03',
            ],
        ]);

        $this->fakeInfrastructure();
        $this->fakeReadOnlyServices();
        Http::preventStrayRequests();
    }

    public function test_preflight_and_payment_dry_run_are_ready_without_signing(): void
    {
        $report = app(MainnetPilotPreflightChecker::class)->check(
            $this->payment->id
        );

        $this->assertTrue($report->ready);
        $this->assertSame('PILOT_PREFLIGHT_READY', $report->toArray()['overall']);
        $this->assertSame('NO', $report->publicContext['activation_release_capable']);
        $this->assertSame('READY', $report->toArray()['signer']);
        $this->assertSame('DISABLED', $report->toArray()['broadcast']);
        $this->assertSame('ACTIVE', $report->toArray()['kill_switch']);
        $this->assertSame('3750000000000000', $report->publicContext['maximum_gas_spend_wei']);
        $this->assertSame('0.01375', $report->publicContext['recommended_funding_kaia']);

        $this->artisan('blockchain:mainnet-pilot-preflight')
            ->expectsOutputToContain('PILOT_PREFLIGHT_READY')
            ->assertSuccessful();
        $this->artisan("payments:prepare-mainnet-pilot {$this->payment->id}")
            ->expectsOutputToContain('PILOT_PREFLIGHT_READY')
            ->assertSuccessful();

        Http::assertNotSent(fn (Request $request): bool => str_contains(
            $request->url(),
            'signAsFeePayer'
        ) || in_array($request['method'] ?? null, [
            'eth_sendRawTransaction',
            'kaia_sendRawTransaction',
        ], true));
        $this->assertDatabaseMissing('payment_fee_delegation_attempts', [
            'payment_id' => $this->payment->id,
        ]);
        $this->assertSame('pending', $this->payment->fresh()->status);
    }

    public function test_wrong_payment_id_amount_sender_and_merchant_fail_closed(): void
    {
        $this->assertFalse(app(MainnetPilotPreflightChecker::class)
            ->check($this->payment->id + 1)->checks['pilot_payment_id']['ready']);

        $this->payment->forceFill([
            'display_amount' => '2',
            'atomic_amount' => '2000000000000000000',
            'amount' => 2,
        ])->saveQuietly();
        $this->assertFalse(app(MainnetPilotPreflightChecker::class)
            ->check()->checks['pilot_payment']['ready']);

        config([
            'services.fee_delegation.mainnet_staging.approved_sender_addresses' => self::FEE_PAYER,
            'services.fee_delegation.mainnet_staging.merchant_address' => self::FEE_PAYER,
        ]);
        $this->assertFalse(app(MainnetPilotPreflightChecker::class)
            ->check()->checks['pilot_identity']['ready']);
    }

    public function test_existing_attempt_and_low_or_excessive_balance_fail_closed(): void
    {
        PaymentFeeDelegationAttempt::create([
            'payment_id' => $this->payment->id,
            'requester_user_id' => $this->user->id,
            'network' => 'kaia-mainnet',
            'chain_id' => 8217,
            'provider' => 'self-hosted',
            'sender_address' => self::SENDER,
            'sender_tx_hash' => '0x'.str_repeat('a', 64),
            'request_fingerprint' => str_repeat('b', 64),
            'state' => 'validated',
            'sender_nonce' => '1',
        ]);
        $this->assertFalse(app(MainnetPilotPreflightChecker::class)
            ->check()->checks['no_existing_attempt']['ready']);

        Http::swap(new Factory);
        $this->fakeReadOnlyServices('0');
        $this->assertFalse(app(MainnetPilotPreflightChecker::class)
            ->check()->checks['fee_payer_balance']['ready']);

        Http::swap(new Factory);
        $this->fakeReadOnlyServices('1');
        $this->assertFalse(app(MainnetPilotPreflightChecker::class)
            ->check()->checks['fee_payer_balance']['ready']);
    }

    public function test_missing_config_or_open_execution_gate_is_not_ready(): void
    {
        config([
            'services.fee_delegation.mainnet_staging.pilot_payment_id' => null,
            'blockchain.mainnet_broadcast_enabled' => true,
        ]);
        $report = app(MainnetPilotPreflightChecker::class)->check();
        $this->assertFalse($report->ready);
        $this->assertFalse($report->checks['pilot_payment_id']['ready']);
        $this->assertFalse($report->checks['staging_readiness']['ready']);
    }

    public function test_pilot_sender_reuse_and_cross_environment_override_are_rejected(): void
    {
        config([
            'services.fee_delegation.mainnet_staging.approved_sender_addresses' => '0x4444444444444444444444444444444444444444',
            'services.fee_delegation.mainnet_staging.allow_cross_environment_reuse' => true,
        ]);

        $report = app(MainnetPilotPreflightChecker::class)->check();

        $this->assertFalse($report->ready);
        $this->assertFalse($report->checks['pilot_identity']['ready']);
    }

    public function test_creates_one_payment_without_network_calls_or_confirmation_user(): void
    {
        Payment::query()->delete();
        config(['services.fee_delegation.mainnet_staging.pilot_payment_id' => null]);
        $before = $this->identityRows();
        $this->artisan('mainnet:create-pilot-payment')
            ->expectsOutputToContain('atomic_amount=1000000000000000000')
            ->expectsOutputToContain('MAINNET_PILOT_PAYMENT_ID=')
            ->assertSuccessful();
        $payment = Payment::query()->sole();
        $snapshot = PaymentSnapshot::fromRecord($payment);
        $this->assertSame('kaia-mainnet', $snapshot->network);
        $this->assertSame(8217, $snapshot->chainId);
        $this->assertSame('1', $snapshot->displayAmount);
        $this->assertSame(self::MERCHANT, $snapshot->recipientAddress);
        $this->assertSame(18, $snapshot->tokenDecimals);
        $this->assertSame('JPYC', $snapshot->tokenSymbol);
        $this->assertSame(1, $snapshot->networkProfileVersion);
        $this->assertNull($payment->user_id); // This is confirmation evidence, not a preassigned payer field.
        $this->assertNull($payment->tx_hash);
        $this->assertSame($before, $this->identityRows());
        $this->assertDatabaseCount('payment_fee_delegation_attempts', 0);
        Http::assertNothingSent();
        $this->artisan('mainnet:create-pilot-payment')->assertFailed();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_creation_rejects_existing_payment_even_without_configured_id(): void
    {
        config(['services.fee_delegation.mainnet_staging.pilot_payment_id' => null]);
        $this->artisan('mainnet:create-pilot-payment')->assertFailed();
        $this->assertDatabaseCount('payments', 1);
        Http::assertNothingSent();
    }

    public function test_creation_fails_closed_for_wrong_environment_database_and_identity(): void
    {
        Payment::query()->delete();
        config(['services.fee_delegation.mainnet_staging.pilot_payment_id' => null]);
        foreach (['testing', 'production', 'local'] as $environment) {
            $this->app['env'] = $environment;
            $this->artisan('mainnet:create-pilot-payment')->assertFailed();
        }
        $this->app['env'] = 'mainnet-staging';
        $good = config('services.fee_delegation.mainnet_staging');
        foreach ([['database_identifier' => 'wrong'], ['kairos_database_identifier' => DB::connection()->getDatabaseName()],
            ['approved_user_ids' => '999'], ['approved_sender_addresses' => self::MERCHANT],
            ['merchant_address' => self::SENDER]] as $override) {
            config(['services.fee_delegation.mainnet_staging' => array_merge($good, $override)]);
            $this->artisan('mainnet:create-pilot-payment')->assertFailed();
        }
        config(['services.fee_delegation.mainnet_staging' => $good]);
        $this->user->update(['wallet_address' => self::FEE_PAYER]);
        $this->artisan('mainnet:create-pilot-payment')->assertFailed();
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    public function test_creation_rolls_back_failed_insert_and_leaves_identities_unchanged(): void
    {
        Payment::query()->delete();
        config(['services.fee_delegation.mainnet_staging.pilot_payment_id' => null]);
        $before = $this->identityRows();
        Payment::creating(function (): never {
            throw new \RuntimeException('forced');
        });
        $this->artisan('mainnet:create-pilot-payment')->assertFailed();
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame($before, $this->identityRows());
    }

    public function test_creation_rejects_open_gates_profile_token_and_store_wallet_mismatch(): void
    {
        Payment::query()->delete();
        config(['services.fee_delegation.mainnet_staging.pilot_payment_id' => null]);
        foreach (['blockchain.payments_mainnet_enabled', 'blockchain.mainnet_fee_delegation_enabled',
            'blockchain.mainnet_broadcast_enabled', 'services.fee_delegation.self_hosted_mainnet.enabled'] as $flag) {
            config([$flag => true]);
            $this->artisan('mainnet:create-pilot-payment')->assertFailed();
            config([$flag => false]);
        }
        config(['services.fee_delegation.self_hosted_mainnet.kill_switch' => false]);
        $this->artisan('mainnet:create-pilot-payment')->assertFailed();
        config(['services.fee_delegation.self_hosted_mainnet.kill_switch' => true, 'blockchain.profiles.kaia-mainnet.chain_id' => 1001]);
        $this->artisan('mainnet:create-pilot-payment')->assertFailed();
        config(['blockchain.profiles.kaia-mainnet.chain_id' => 8217, 'blockchain.profiles.kaia-mainnet.jpyc.contract' => self::MERCHANT]);
        $this->artisan('mainnet:create-pilot-payment')->assertFailed();
        config(['blockchain.profiles.kaia-mainnet.jpyc.contract' => '0xe7c3d8c9a439fede00d2600032d5db0be71c3c29']);
        Wallet::query()->update(['network' => 'kairos']);
        $this->artisan('mainnet:create-pilot-payment')->assertFailed();
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    public function test_funding_plan_supports_zero_balance_and_exact_one_wei_top_up(): void
    {
        Http::swap(new Factory);
        $this->fakeReadOnlyServices('0');
        $before = $this->databaseRows();
        $this->artisan('mainnet:pilot-funding-plan')
            ->expectsOutputToContain('max_transaction_fee_wei=3750000000000000')
            ->expectsOutputToContain('current_balance_kaia=0')
            ->expectsOutputToContain('recommended_top_up_kaia=0.01375')->assertSuccessful();
        Http::swap(new Factory);
        $this->fakeReadOnlyServices('0.013749999999999999');
        $this->artisan('mainnet:pilot-funding-plan')
            ->expectsOutputToContain('recommended_top_up_kaia=0.000000000000000001')->assertSuccessful();
        Http::swap(new Factory);
        $this->fakeReadOnlyServices('0.02');
        $this->artisan('mainnet:pilot-funding-plan')
            ->expectsOutputToContain('recommended_top_up_kaia=0')->assertSuccessful();
        $this->assertSame($before, $this->databaseRows());
    }

    public function test_funding_plan_rejects_bad_config_excess_balance_and_rpc_failure(): void
    {
        config(['services.fee_delegation.mainnet_staging.pilot_max_fee_payer_balance_kaia' => '0.001']);
        $this->artisan('mainnet:pilot-funding-plan')->assertFailed();
        config(['services.fee_delegation.mainnet_staging.pilot_max_fee_payer_balance_kaia' => '0.03']);
        Http::swap(new Factory);
        $this->fakeReadOnlyServices('1');
        $this->artisan('mainnet:pilot-funding-plan')->assertFailed();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], 503)]);
        $this->artisan('mainnet:pilot-funding-plan')->assertFailed();
    }

    public function test_read_only_commands_do_not_issue_any_database_writes(): void
    {
        $before = $this->databaseRows();
        DB::enableQueryLog();
        $this->artisan('payments:prepare-mainnet-pilot', ['paymentId' => $this->payment->id])
            ->expectsOutputToContain('READY_FOR_HUMAN_APPROVAL')->assertSuccessful();
        $this->artisan('mainnet:pilot-funding-plan')->assertSuccessful();
        $this->artisan('mainnet:pilot-gas-observation')->assertSuccessful();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(insert|update|delete|replace|alter|create|drop)\b/i', $query['query']);
        }
        $this->assertSame($before, $this->databaseRows());
        Http::assertNotSent(fn (Request $request) => ! in_array($request['method'] ?? 'health', [
            'health', 'eth_chainId', 'eth_getCode', 'eth_call', 'eth_getBlockByNumber',
            'eth_blockNumber', 'eth_getBalance', 'eth_gasPrice', 'eth_estimateGas',
        ], true));
    }

    public function test_preflight_diagnostics_do_not_invent_an_attempt_for_invalid_id(): void
    {
        config(['services.fee_delegation.mainnet_staging.pilot_payment_id' => null]);
        $report = app(MainnetPilotPreflightChecker::class)->check();
        $this->assertSame('not_checked_without_valid_payment', $report->checks['no_existing_attempt']['diagnostic']);
        $this->assertSame('NOT_READY', $report->toArray()['policy']);
    }

    public function test_preflight_rejects_wrong_wallet_expired_payment_and_remote_policy_drift(): void
    {
        $this->user->update(['wallet_address' => self::FEE_PAYER]);
        $this->assertFalse(app(MainnetPilotPreflightChecker::class)->check()->checks['pilot_identity']['ready']);
        $this->user->update(['wallet_address' => self::SENDER]);
        $this->payment->forceFill(['expires_at' => now()->subSecond()])->saveQuietly();
        $this->assertFalse(app(MainnetPilotPreflightChecker::class)->check()->checks['pilot_payment']['ready']);
        config(['services.fee_delegation.self_hosted_mainnet.max_gas' => 140000]);
        $this->assertFalse(app(MainnetPilotPreflightChecker::class)->check()->checks['fee_payer_policy']['ready']);
    }

    public function test_preflight_accepts_multiple_preserved_expired_unused_pilots_before_current_payment(): void
    {
        $this->createPreservedHistoryAndCurrent(3);

        $report = app(MainnetPilotPreflightChecker::class)->check();

        $this->assertTrue($report->ready);
        $this->assertTrue($report->checks['pilot_payment']['ready']);
        $this->assertDatabaseCount('payments', 4);
        $this->assertDatabaseCount('payment_fee_delegation_attempts', 0);
    }

    public function test_preflight_rejects_invalid_history_attempt_and_non_latest_configured_id(): void
    {
        [$old, $current] = $this->createPreservedHistoryAndCurrent(2);
        $old->forceFill(['expires_at' => now()->addSecond()])->saveQuietly();
        $this->assertFalse(app(MainnetPilotPreflightChecker::class)->check()->checks['pilot_payment']['ready']);

        $old->forceFill(['expires_at' => now()->subSecond()])->saveQuietly();
        PaymentFeeDelegationAttempt::query()->create([
            'payment_id' => $old->id,
            'requester_user_id' => $this->user->id,
            'network' => 'kaia-mainnet',
            'chain_id' => 8217,
            'provider' => 'self-hosted',
            'sender_address' => self::SENDER,
            'sender_tx_hash' => '0x'.str_repeat('a', 64),
            'request_fingerprint' => str_repeat('b', 64),
            'state' => 'unknown_submission',
            'sender_nonce' => '1',
        ]);
        $report = app(MainnetPilotPreflightChecker::class)->check();
        $this->assertFalse($report->checks['pilot_payment']['ready']);
        $this->assertFalse($report->checks['no_existing_attempt']['ready']);

        PaymentFeeDelegationAttempt::query()->delete();
        config(['services.fee_delegation.mainnet_staging.pilot_payment_id' => $old->id]);
        $this->assertFalse(app(MainnetPilotPreflightChecker::class)->check()->checks['pilot_payment']['ready']);

        config(['services.fee_delegation.mainnet_staging.pilot_payment_id' => $current->id]);
        $old->forceFill(['recipient_address' => self::FEE_PAYER])->saveQuietly();
        $this->assertFalse(app(MainnetPilotPreflightChecker::class)->check()->checks['pilot_payment']['ready']);
    }

    public function test_preflight_reports_actual_gate_failure_and_checks_all_single_attempt_limits(): void
    {
        config(['blockchain.mainnet_broadcast_enabled' => true, 'services.fee_delegation.self_hosted_mainnet.kill_switch' => false]);
        $report = app(MainnetPilotPreflightChecker::class)->check();
        $this->assertSame('NOT_DISABLED', $report->toArray()['broadcast']);
        $this->assertSame('NOT_ACTIVE', $report->toArray()['kill_switch']);
        config(['blockchain.mainnet_broadcast_enabled' => false, 'services.fee_delegation.self_hosted_mainnet.kill_switch' => true]);
        foreach (['max_payment_jpy', 'max_attempts_per_user', 'max_attempts_per_store', 'max_attempts_per_sender',
            'max_attempts_global', 'daily_transaction_limit'] as $key) {
            config(['services.fee_delegation.self_hosted_mainnet.'.$key => 2]);
            $this->assertFalse(app(MainnetPilotPreflightChecker::class)->check()->checks['pilot_limits']['ready']);
            config(['services.fee_delegation.self_hosted_mainnet.'.$key => 1]);
        }
        config(['services.fee_delegation.self_hosted_mainnet.rate_window_seconds' => 1]);
        $this->assertFalse(app(MainnetPilotPreflightChecker::class)->check()->checks['pilot_limits']['ready']);
    }

    public function test_rotation_requires_confirmation_and_changes_only_credential_hashes(): void
    {
        $storeId = $this->payment->store_id;
        $userId = $this->user->id;
        $before = $this->databaseRows();
        $question = "Rotate credentials for pilot Store {$storeId} / User {$userId}?";
        $this->artisan('mainnet:rotate-pilot-credentials')->expectsConfirmation($question, 'no')->assertFailed();
        $this->assertSame($before, $this->databaseRows());
        $this->artisan('mainnet:rotate-pilot-credentials')->expectsConfirmation($question, 'yes')->assertSuccessful();
        $after = $this->databaseRows();
        foreach (['stores' => 'store_pin', 'staffs' => 'pin', 'users' => 'password'] as $table => $column) {
            $this->assertNotSame($before[$table][0][$column], $after[$table][0][$column]);
            $this->assertFalse(Hash::check('1234', $after[$table][0][$column]));
            unset($before[$table][0][$column], $after[$table][0][$column], $before[$table][0]['updated_at'], $after[$table][0]['updated_at']);
        }
        $this->assertSame($before, $after);
        Http::assertNothingSent();
    }

    public function test_rotation_failure_rolls_back_every_hash(): void
    {
        $before = $this->databaseRows();
        User::updating(function (): never {
            throw new \RuntimeException('forced');
        });
        $question = "Rotate credentials for pilot Store {$this->payment->store_id} / User {$this->user->id}?";
        $this->artisan('mainnet:rotate-pilot-credentials')->expectsConfirmation($question, 'yes')->assertFailed();
        $this->assertSame($before, $this->databaseRows());
    }

    public function test_mainnet_details_include_only_matching_approved_pilot_metadata(): void
    {
        $this->getJson('/api/payments/'.$this->payment->id)->assertOk()
            ->assertJsonPath('network_profile_version', 1)
            ->assertJsonPath('mainnet_pilot.user_id', $this->user->id)
            ->assertJsonPath('mainnet_pilot.sender_address', self::SENDER)
            ->assertJsonPath('mainnet_pilot.merchant_address', self::MERCHANT);
        config(['services.fee_delegation.mainnet_staging.pilot_payment_id' => 999]);
        $this->getJson('/api/payments/'.$this->payment->id)->assertOk()->assertJsonPath('mainnet_pilot', null);
    }

    public function test_shutdown_status_is_read_only_and_refuses_unknown_remote_or_open_local_gates(): void
    {
        $before = $this->databaseRows();
        $this->artisan('mainnet:pilot-gate-status')->expectsOutputToContain('SHUTDOWN_VERIFIED')->assertSuccessful();
        $this->artisan('mainnet:pilot-shutdown-check')->assertSuccessful();
        config(['blockchain.mainnet_broadcast_enabled' => true]);
        $this->artisan('mainnet:pilot-shutdown-check')->expectsOutputToContain('local_broadcast=NOT_DISABLED')->assertFailed();
        config(['blockchain.mainnet_broadcast_enabled' => false]);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], 503)]);
        $this->artisan('mainnet:pilot-shutdown-check')->expectsOutputToContain('fee_payer_gates=NOT_VERIFIED')->assertFailed();
        $this->assertSame($before, $this->databaseRows());
    }

    private function identityRows(): array
    {
        $rows = [];
        foreach (['stores', 'staffs', 'wallets', 'users'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $rows;
    }

    private function databaseRows(): array
    {
        $rows = $this->identityRows();
        foreach (['payments', 'payment_fee_delegation_attempts'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $rows;
    }

    private function createPreservedHistoryAndCurrent(int $historyCount): array
    {
        $oldest = $this->payment;
        $storeId = $this->payment->store_id;
        $staffId = $this->payment->staff_id;
        $this->payment->forceFill(['expires_at' => now()->subSecond()])->saveQuietly();
        for ($index = 1; $index < $historyCount; $index++) {
            $expired = PaymentSnapshot::create(
                app(NetworkProfileRegistry::class)->get('kaia-mainnet'),
                self::MERCHANT,
                '1',
                now()->subSecond(),
                1
            );
            Payment::query()->create(array_merge([
                'store_id' => $storeId,
                'staff_id' => $staffId,
                'amount' => 1,
                'status' => 'pending',
            ], $expired->databaseAttributes()));
        }
        $current = PaymentSnapshot::create(
            app(NetworkProfileRegistry::class)->get('kaia-mainnet'),
            self::MERCHANT,
            '1',
            now()->addHours(6),
            1
        );
        $this->payment = Payment::query()->create(array_merge([
            'store_id' => $storeId,
            'staff_id' => $staffId,
            'amount' => 1,
            'status' => 'pending',
        ], $current->databaseAttributes()));
        config([
            'services.fee_delegation.mainnet_staging.pilot_payment_id' => $this->payment->id,
        ]);

        return [$oldest->fresh(), $this->payment];
    }

    private function createPilotPayment(): array
    {
        $store = Store::create([
            'store_code' => 'pilot-store',
            'store_pin' => 'pilot-pin',
            'name' => 'Pilot Store',
        ]);
        $staff = Staff::create([
            'store_id' => $store->id,
            'staff_id' => 'pilot-staff',
            'name' => 'Pilot Staff',
            'pin' => 'pilot-pin',
            'role' => 'staff',
        ]);
        $user = User::create([
            'name' => 'Pilot User',
            'email' => 'pilot@example.test',
            'password' => 'not-used',
            'wallet_address' => self::SENDER,
        ]);
        Wallet::create(['store_id' => $store->id, 'address' => self::MERCHANT, 'network' => 'kaia-mainnet']);
        $expiresAt = now()->addMinutes(10);
        $snapshot = PaymentSnapshot::create(
            app(NetworkProfileRegistry::class)->get('kaia-mainnet'),
            self::MERCHANT,
            1,
            $expiresAt
        );
        $payment = Payment::create(array_merge([
            'store_id' => $store->id,
            'staff_id' => $staff->id,
            'amount' => 1,
            'status' => 'pending',
            'expires_at' => $expiresAt,
        ], $snapshot->databaseAttributes()));

        return [$payment, $user];
    }

    private function fakeInfrastructure(): void
    {
        $this->app->instance(
            MainnetStagingInfrastructure::class,
            new class extends MainnetStagingInfrastructure
            {
                public function database(): array
                {
                    return [
                        'ready' => true,
                        'name' => DB::connection()->getDatabaseName(),
                        'migrations' => [
                            '2026_09_04_000000_add_payment_snapshot_to_payments_table',
                            '2026_09_06_000000_add_confirmation_evidence_to_payments_table',
                            '2026_09_06_500000_create_payment_fee_delegation_attempts_table',
                            '2026_09_07_000000_harden_payments_for_chain_aware_uniqueness',
                        ],
                    ];
                }

                public function cacheLock(): array
                {
                    return [
                        'ready' => true,
                        'driver' => 'redis',
                        'prefix' => 'livt-mainnet-staging-cache-',
                    ];
                }

                public function cacheConfiguration(): array
                {
                    return $this->cacheLock();
                }
            }
        );
    }

    private function fakeReadOnlyServices(string $balance = '0.02'): void
    {
        Http::fake(function (Request $request) use ($balance) {
            if ($request->url() === 'http://127.0.0.1:19000/health') {
                return Http::response([
                    'status' => 'READ_ONLY_READY',
                    'network' => 'kaia-mainnet',
                    'chain_id' => 8217,
                    'fee_payer_address' => self::FEE_PAYER,
                    'balance_kaia' => $balance,
                    'signer_status' => 'SIGNER_READY',
                    'signer_type' => 'aws-kms',
                    'signer_key_reference' => 'sha256:0123456789abcdef',
                    'kill_switch' => 'ACTIVE',
                    'execution' => 'DISABLED',
                    'signing' => 'DISABLED',
                    'broadcast' => 'DISABLED',
                    'pilot_policy' => [
                        'ready' => true, 'max_payment_jpyc' => '1', 'max_gas' => '150000',
                        'max_gas_price_wei' => '25000000000', 'rate_window_seconds' => '3600',
                        'max_attempts_per_user' => '1', 'max_attempts_per_store' => '1',
                        'max_attempts_per_sender' => '1', 'max_attempts_global' => '1',
                        'daily_transaction_limit' => '1', 'daily_kaia_budget_wei' => '3750000000000000',
                        'minimum_reserve_wei' => '10000000000000000', 'maximum_balance_wei' => '30000000000000000',
                        'merchant_address' => self::MERCHANT, 'sender_address' => self::SENDER,
                        'pilot_payment_id' => (string) config('services.fee_delegation.mainnet_staging.pilot_payment_id'),
                    ],
                ]);
            }

            $result = match ($request['method']) {
                'eth_chainId' => '0x2019',
                'eth_blockNumber' => '0x64',
                'eth_getBalance' => '0x'.gmp_strval(gmp_init(MainnetPilotLimits::kaiaToWei($balance), 10), 16),
                'eth_estimateGas' => '0x186a0',
                'eth_gasPrice' => '0x5d21dba00',
                'eth_getCode' => '0x6000',
                'eth_call' => $request['params'][0]['data'] === '0x313ce567'
                    ? '0x'.str_pad(dechex(18), 64, '0', STR_PAD_LEFT)
                    : $this->abiString('JPYC'),
                'eth_getBlockByNumber' => [
                    'number' => '0x64',
                    'hash' => '0x'.str_repeat('a', 64),
                    'timestamp' => '0x65000000',
                ],
                default => null,
            };

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => $result,
            ]);
        });
    }

    private function abiString(string $value): string
    {
        return '0x'
            .str_pad('20', 64, '0', STR_PAD_LEFT)
            .str_pad(dechex(strlen($value)), 64, '0', STR_PAD_LEFT)
            .str_pad(bin2hex($value), 64, '0');
    }
}
