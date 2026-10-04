<?php

namespace Tests\Feature;

use App\Blockchain\MainnetPilotGateStateChecker;
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
use App\Services\Payments\MainnetPaymentAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
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
            'services.fee_delegation.enabled' => false,
            'services.fee_delegation.url' => 'http://127.0.0.1:19000',
            'services.fee_delegation.api_key' => 'test-only-key',
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
                'authorization_key' => str_repeat('c', 64),
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
        $this->assertSame(
            config('blockchain.mainnet_activation_release_capable') === true ? 'YES' : 'NO',
            $report->publicContext['activation_release_capable']
        );
        $this->assertSame('READY', $report->toArray()['signer']);
        $this->assertSame('DISABLED', $report->toArray()['broadcast']);
        $this->assertSame('ACTIVE', $report->toArray()['kill_switch']);
        $this->assertSame('3750000000000000', $report->publicContext['maximum_gas_spend_wei']);
        $this->assertSame('0.01375', $report->publicContext['recommended_funding_kaia']);
        $this->assertTrue($report->checks['sender_jpyc_balance']['ready']);
        $this->assertSame('2000000000000000000', $report->publicContext['sender_jpyc_atomic']);
        $this->assertSame('1000000000000000000', $report->publicContext['observed_transfer_atomic_amount']);

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

    public function test_store_can_create_mainnet_qr_payment_while_all_execution_gates_are_closed(): void
    {
        $staff = Staff::query()->sole();
        Sanctum::actingAs($staff, ['payment:create']);
        // The generic Kaia switch may remain on; the three Mainnet-specific
        // execution switches and self-hosted gate still prevent execution.
        config(['services.fee_delegation.enabled' => true]);

        $this->getJson('/api/staff/pos/context')
            ->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('creation_available', true)
            ->assertJsonPath('network.id', 'kaia-mainnet')
            ->assertJsonPath('diagnostic', 'none');

        $response = $this->postJson('/api/payments/create', ['amount' => 1])
            ->assertOk()
            ->assertJsonPath('amount', 1)
            ->assertJsonPath('network', 'kaia-mainnet')
            ->assertJsonPath('recipient_address', self::MERCHANT);

        $payment = Payment::query()->findOrFail($response->json('payment_id'));
        $this->assertSame('pending', $payment->status);
        $this->assertNotNull($payment->mainnet_authorized_at);
        $this->assertDatabaseCount('payment_fee_delegation_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_store_payment_creation_rejects_a_partially_open_mainnet_gate_set(): void
    {
        $staff = Staff::query()->sole();
        Sanctum::actingAs($staff, ['payment:create']);
        config(['blockchain.payments_mainnet_enabled' => true]);

        $this->getJson('/api/staff/pos/context')
            ->assertOk()
            ->assertJsonPath('creation_available', false)
            ->assertJsonPath('diagnostic', 'payment_execution_disabled');
        $this->postJson('/api/payments/create', ['amount' => 1])
            ->assertServiceUnavailable()
            ->assertJsonPath('error', 'Mainnet payment authorization unavailable');

        $this->assertDatabaseCount('payments', 1);
        Http::assertNothingSent();
    }

    public function test_preflight_stops_before_gas_estimation_when_sender_jpyc_is_insufficient(): void
    {
        $this->fakeReadOnlyServices(senderJpycAtomic: '0');

        $report = app(MainnetPilotPreflightChecker::class)->check($this->payment->id);

        $this->assertFalse($report->ready);
        $this->assertFalse($report->checks['sender_jpyc_balance']['ready']);
        $this->assertSame(
            'sender_jpyc_balance_unreadable_or_insufficient',
            $report->checks['sender_jpyc_balance']['diagnostic']
        );
        $this->assertFalse($report->checks['gas_observation']['ready']);
        $this->assertSame('0', $report->publicContext['sender_jpyc_atomic']);
        Http::assertNotSent(fn (Request $request): bool => ($request['method'] ?? null) === 'eth_estimateGas');
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
        $this->assertNotNull($payment->mainnet_authorized_at);
        $this->assertSame($before, $this->identityRows());
        $this->assertDatabaseCount('payment_fee_delegation_attempts', 0);
        Http::assertNothingSent();
        $this->artisan('mainnet:create-pilot-payment')->assertFailed();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_creation_accepts_configured_variable_amount_and_rejects_amount_above_limit(): void
    {
        Payment::query()->delete();
        config([
            'services.fee_delegation.mainnet_staging.pilot_payment_id' => null,
            'services.fee_delegation.self_hosted_mainnet.max_payment_jpy' => 125,
        ]);

        $this->artisan('mainnet:create-pilot-payment', ['--amount' => '125'])
            ->expectsOutputToContain('display_amount=125')
            ->expectsOutputToContain('atomic_amount=125000000000000000000')
            ->assertSuccessful();

        $payment = Payment::query()->sole();
        $snapshot = PaymentSnapshot::fromRecord($payment);
        $this->assertSame(125, $payment->amount);
        $this->assertSame('125', $snapshot->displayAmount);
        $this->assertSame('125000000000000000000', $snapshot->atomicAmount);

        Payment::query()->delete();
        $this->artisan('mainnet:create-pilot-payment', ['--amount' => '126'])
            ->assertFailed();
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
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

    public function test_ten_payment_budget_requires_daily_budget_plus_reserve(): void
    {
        config([
            'services.fee_delegation.self_hosted_mainnet.max_attempts_per_user' => 10,
            'services.fee_delegation.self_hosted_mainnet.max_attempts_per_store' => 10,
            'services.fee_delegation.self_hosted_mainnet.max_attempts_per_sender' => 10,
            'services.fee_delegation.self_hosted_mainnet.max_attempts_global' => 10,
            'services.fee_delegation.self_hosted_mainnet.daily_transaction_limit' => 10,
            'services.fee_delegation.self_hosted_mainnet.daily_kaia_budget' => '0.0375',
            'services.fee_delegation.mainnet_staging.pilot_max_fee_payer_balance_kaia' => '0.0475',
        ]);
        $policy = [
            'max_attempts_per_user' => '10',
            'max_attempts_per_store' => '10',
            'max_attempts_per_sender' => '10',
            'max_attempts_global' => '10',
            'daily_transaction_limit' => '10',
            'daily_kaia_budget_wei' => '37500000000000000',
            'maximum_balance_wei' => '47500000000000000',
        ];
        $this->fakeReadOnlyServices('0.0475', healthOverrides: ['pilot_policy' => $policy]);

        $report = app(MainnetPilotPreflightChecker::class)->check($this->payment->id);

        $this->assertTrue($report->ready);
        $this->assertSame('47500000000000000', $report->publicContext['recommended_funding_wei']);
        $this->assertSame('0.0475', $report->publicContext['recommended_funding_kaia']);

        $this->fakeReadOnlyServices('0.047499999999999999', healthOverrides: ['pilot_policy' => $policy]);
        $report = app(MainnetPilotPreflightChecker::class)->check($this->payment->id);
        $this->assertFalse($report->checks['fee_payer_balance']['ready']);

        config(['services.fee_delegation.mainnet_staging.pilot_max_fee_payer_balance_kaia' => '0.047499999999999999']);
        $this->expectException(\RuntimeException::class);
        app(MainnetPilotLimits::class)->validate();
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

    public function test_preflight_accepts_strongly_bound_legacy_confirmed_payment_as_closed_history(): void
    {
        [$confirmed, $current] = $this->createConfirmedHistoryAndCurrent();

        $report = app(MainnetPilotPreflightChecker::class)->check();

        $this->assertTrue($report->ready);
        $this->assertTrue($report->checks['pilot_payment']['ready']);
        $this->assertSame('confirmed', $confirmed->fresh()->status);
        $this->assertSame('pending', $current->fresh()->status);
        $this->assertDatabaseCount('payment_fee_delegation_attempts', 1);
    }

    public function test_preflight_rejects_legacy_confirmed_history_when_attempt_binding_is_incomplete(): void
    {
        [$confirmed] = $this->createConfirmedHistoryAndCurrent();
        PaymentFeeDelegationAttempt::query()
            ->where('payment_id', $confirmed->id)
            ->update(['sender_address' => self::FEE_PAYER]);

        $report = app(MainnetPilotPreflightChecker::class)->check();

        $this->assertFalse($report->checks['pilot_payment']['ready']);
        $this->assertTrue($report->checks['no_existing_attempt']['ready']);
    }

    public function test_preflight_rejects_confirmed_history_with_reconciliation_anomaly(): void
    {
        [$confirmed] = $this->createConfirmedHistoryAndCurrent();
        $confirmed->forceFill(['reconciliation_status' => 'anomaly'])->saveQuietly();

        $report = app(MainnetPilotPreflightChecker::class)->check();

        $this->assertFalse($report->checks['pilot_payment']['ready']);
    }

    public function test_preflight_rejects_invalid_history_attempt_and_non_latest_configured_id(): void
    {
        [$old, $current] = $this->createPreservedHistoryAndCurrent(2);
        $old->forceFill(['expires_at' => now()->addMinute()])->saveQuietly();
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
        $this->assertTrue($report->checks['no_existing_attempt']['ready']);

        PaymentFeeDelegationAttempt::query()->delete();
        config(['services.fee_delegation.mainnet_staging.pilot_payment_id' => $old->id]);
        $this->assertFalse(app(MainnetPilotPreflightChecker::class)->check()->checks['pilot_payment']['ready']);

        config(['services.fee_delegation.mainnet_staging.pilot_payment_id' => $current->id]);
        $old->forceFill(['recipient_address' => self::FEE_PAYER])->saveQuietly();
        $this->assertFalse(app(MainnetPilotPreflightChecker::class)->check()->checks['pilot_payment']['ready']);
    }

    public function test_preflight_reports_actual_gate_failure_and_checks_bounded_attempt_limits(): void
    {
        config(['blockchain.mainnet_broadcast_enabled' => true, 'services.fee_delegation.self_hosted_mainnet.kill_switch' => false]);
        $report = app(MainnetPilotPreflightChecker::class)->check();
        $this->assertSame('NOT_DISABLED', $report->toArray()['broadcast']);
        $this->assertSame('NOT_ACTIVE', $report->toArray()['kill_switch']);
        config(['blockchain.mainnet_broadcast_enabled' => false, 'services.fee_delegation.self_hosted_mainnet.kill_switch' => true]);
        config([
            'services.fee_delegation.self_hosted_mainnet.max_attempts_per_user' => 2,
            'services.fee_delegation.self_hosted_mainnet.max_attempts_per_store' => 2,
            'services.fee_delegation.self_hosted_mainnet.max_attempts_per_sender' => 2,
            'services.fee_delegation.self_hosted_mainnet.max_attempts_global' => 2,
            'services.fee_delegation.self_hosted_mainnet.daily_transaction_limit' => 2,
        ]);
        $this->assertTrue(app(MainnetPilotPreflightChecker::class)->check()->checks['pilot_limits']['ready']);
        config(['services.fee_delegation.self_hosted_mainnet.max_attempts_global' => 1001]);
        $this->assertFalse(app(MainnetPilotPreflightChecker::class)->check()->checks['pilot_limits']['ready']);
        config([
            'services.fee_delegation.self_hosted_mainnet.max_attempts_global' => 1,
            'services.fee_delegation.self_hosted_mainnet.max_attempts_per_user' => 2,
        ]);
        $this->assertFalse(app(MainnetPilotPreflightChecker::class)->check()->checks['pilot_limits']['ready']);
        config([
            'services.fee_delegation.self_hosted_mainnet.max_attempts_per_user' => 1,
            'services.fee_delegation.self_hosted_mainnet.max_attempts_per_store' => 1,
            'services.fee_delegation.self_hosted_mainnet.max_attempts_per_sender' => 1,
            'services.fee_delegation.self_hosted_mainnet.max_attempts_global' => 1,
            'services.fee_delegation.self_hosted_mainnet.daily_transaction_limit' => 1,
        ]);
        config([
            'services.fee_delegation.self_hosted_mainnet.max_payment_jpy' => 100001,
            'blockchain.payment_max_jpy' => 100000,
        ]);
        $this->assertFalse(app(MainnetPilotPreflightChecker::class)->check()->checks['pilot_limits']['ready']);
        config(['services.fee_delegation.self_hosted_mainnet.max_payment_jpy' => 1]);
        config(['services.fee_delegation.self_hosted_mainnet.rate_window_seconds' => 1]);
        $this->assertFalse(app(MainnetPilotPreflightChecker::class)->check()->checks['pilot_limits']['ready']);
        config([
            'services.fee_delegation.self_hosted_mainnet.rate_window_seconds' => 3600,
            'services.fee_delegation.self_hosted_mainnet.authorization_key' => null,
        ]);
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

    public function test_staff_pin_rotation_changes_only_staff_pin_and_revokes_staff_tokens(): void
    {
        $staff = Staff::query()->sole();
        $staff->createToken('old-pos-token', ['payment:create']);
        $before = $this->databaseRows();
        $secretFile = sys_get_temp_dir().'/livt-staff-pin-test-'.bin2hex(random_bytes(8));
        $question = "Rotate only the Staff PIN for pilot Store {$staff->store_id} / Staff {$staff->id}?";

        try {
            $this->artisan('mainnet:rotate-pilot-staff-pin', [
                '--secret-file' => $secretFile,
            ])->expectsConfirmation($question, 'yes')->assertSuccessful();

            $after = $this->databaseRows();
            $this->assertNotSame(
                $before['staffs'][0]['pin'],
                $after['staffs'][0]['pin']
            );
            $newPin = trim((string) file_get_contents($secretFile));
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $newPin);
            $this->assertTrue(Hash::check($newPin, $after['staffs'][0]['pin']));
            $this->assertSame(0600, fileperms($secretFile) & 0777);

            foreach (['stores' => 'store_pin', 'users' => 'password'] as $table => $column) {
                $this->assertSame($before[$table][0][$column], $after[$table][0][$column]);
            }
            $this->assertSame(
                $before['wallets'][0]['address'],
                $after['wallets'][0]['address']
            );
            $this->assertDatabaseCount('personal_access_tokens', 0);
            Http::assertNothingSent();
        } finally {
            if (file_exists($secretFile)) {
                unlink($secretFile);
            }
        }
    }

    public function test_staff_pin_rotation_refuses_without_confirmation_or_safe_secret_file(): void
    {
        $staff = Staff::query()->sole();
        $before = $staff->pin;
        $secretFile = sys_get_temp_dir().'/livt-staff-pin-test-'.bin2hex(random_bytes(8));
        $question = "Rotate only the Staff PIN for pilot Store {$staff->store_id} / Staff {$staff->id}?";

        $this->artisan('mainnet:rotate-pilot-staff-pin', [
            '--secret-file' => $secretFile,
        ])->expectsConfirmation($question, 'no')->assertFailed();
        $this->assertFileDoesNotExist($secretFile);
        $this->assertSame($before, $staff->fresh()->pin);

        file_put_contents($secretFile, 'do-not-overwrite');
        try {
            $this->artisan('mainnet:rotate-pilot-staff-pin', [
                '--secret-file' => $secretFile,
            ])->assertFailed();
            $this->assertSame('do-not-overwrite', file_get_contents($secretFile));
            $this->assertSame($before, $staff->fresh()->pin);
        } finally {
            unlink($secretFile);
        }
    }

    public function test_mainnet_details_include_only_matching_approved_pilot_metadata(): void
    {
        $this->getJson('/api/payments/'.$this->payment->id)->assertOk()
            ->assertJsonPath('network_profile_version', 1)
            ->assertJsonPath('mainnet_pilot.user_id', $this->user->id)
            ->assertJsonPath('mainnet_pilot.sender_address', self::SENDER)
            ->assertJsonPath('mainnet_pilot.merchant_address', self::MERCHANT)
            ->assertJsonPath('mainnet_pilot.max_payment_jpyc', '1');
        config(['services.fee_delegation.mainnet_staging.pilot_payment_id' => 999]);
        $this->getJson('/api/payments/'.$this->payment->id)->assertOk()
            ->assertJsonPath('mainnet_pilot.payment_id', $this->payment->id);
    }

    public function test_mainnet_details_and_model_fail_closed_without_immutable_authorization(): void
    {
        DB::table('payments')->where('id', $this->payment->id)->update([
            'mainnet_authorized_at' => null,
        ]);
        $this->payment->refresh();

        $this->getJson('/api/payments/'.$this->payment->id)
            ->assertOk()
            ->assertJsonPath('mainnet_pilot', null);
        $this->assertFalse(
            app(MainnetPilotPreflightChecker::class)
                ->check($this->payment->id)
                ->checks['pilot_payment']['ready']
        );

        $this->expectException(\LogicException::class);
        $this->payment->update(['mainnet_authorized_at' => now()]);
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

    public function test_mainnet_sponsorship_availability_follows_live_gate_state(): void
    {
        $url = '/api/payments/'.$this->payment->id.'/sponsorship';
        $this->getJson($url)->assertOk()->assertExactJson(['available' => false]);
        $this->assertSame('SHUTDOWN_VERIFIED', app(MainnetPilotGateStateChecker::class)->check()['state']);

        config([
            'blockchain.mainnet_activation_release_capable' => true,
            'blockchain.profiles.kaia-mainnet.payment_execution_enabled' => true,
            'blockchain.profiles.kaia-mainnet.fee_delegation_execution_enabled' => true,
        ]);
        $this->fakeReadOnlyServices(healthOverrides: ['activation_release_capable' => true]);
        $this->assertSame('SAFE_DEPLOYED', app(MainnetPilotGateStateChecker::class)->check()['state']);
        $this->getJson($url)->assertOk()->assertExactJson(['available' => false]);

        $this->enableLiveGateForTest();
        $this->fakeReadOnlyServices(healthOverrides: $this->liveHealth());
        $liveState = app(MainnetPilotGateStateChecker::class)->check();
        $this->assertSame('LIVE_ENABLED', $liveState['state'], json_encode($liveState['context']));
        $this->getJson($url)->assertOk()->assertExactJson(['available' => true]);

        config(['services.fee_delegation.self_hosted_mainnet.kill_switch' => true]);
        $this->assertSame('ARMED_NOT_LIVE', app(MainnetPilotGateStateChecker::class)->check()['state']);
        $this->getJson($url)->assertOk()->assertExactJson(['available' => false]);
        config(['services.fee_delegation.self_hosted_mainnet.kill_switch' => false]);

        foreach (['execution', 'signing', 'broadcast'] as $gate) {
            $this->fakeReadOnlyServices(healthOverrides: $this->liveHealth([$gate => 'DISABLED']));
            $this->getJson($url)->assertOk()->assertExactJson(['available' => false]);
        }
        $this->fakeReadOnlyServices(healthOverrides: $this->liveHealth(['kill_switch' => 'ACTIVE']));
        $this->getJson($url)->assertOk()->assertExactJson(['available' => false]);
        $this->fakeReadOnlyServices(healthOverrides: $this->liveHealth(['pilot_policy' => ['sender_address' => self::MERCHANT]]));
        $this->getJson($url)->assertOk()->assertExactJson(['available' => false]);
        config(['services.fee_delegation.self_hosted_mainnet.max_payment_jpy' => 2]);
        $this->fakeReadOnlyServices(healthOverrides: $this->liveHealth());
        $this->getJson($url)->assertOk()->assertExactJson(['available' => false]);
        config(['services.fee_delegation.self_hosted_mainnet.max_payment_jpy' => 1]);
        $this->fakeReadOnlyServices(healthOverrides: $this->liveHealth(), healthStatus: 503);
        $this->getJson($url)->assertOk()->assertExactJson(['available' => false]);
        $this->fakeReadOnlyServices(healthOverrides: $this->liveHealth(), healthStatus: 500);
        $this->getJson($url)->assertOk()->assertExactJson(['available' => false]);
        $this->fakeReadOnlyServices(healthOverrides: $this->liveHealth(['status' => 'READ_ONLY_READY']));
        $this->getJson($url)->assertOk()->assertExactJson(['available' => false]);
        $this->fakeReadOnlyServices(healthOverrides: $this->liveHealth(['status' => 'malformed']));
        $this->assertSame('NOT_VERIFIED', app(MainnetPilotGateStateChecker::class)->check()['state']);
        $this->getJson($url)->assertOk()->assertExactJson(['available' => false]);

        $this->fakeReadOnlyServices(healthOverrides: $this->liveHealth());
        $this->payment->forceFill(['expires_at' => now()->subSecond()])->saveQuietly();
        $this->getJson($url)->assertOk()->assertExactJson(['available' => false]);
        $this->assertDatabaseCount('payment_fee_delegation_attempts', 0);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/sponsorship'));
    }

    public function test_live_availability_rejects_attempt_and_sender_mismatch_without_writes(): void
    {
        $this->enableLiveGateForTest();
        $this->fakeReadOnlyServices(healthOverrides: $this->liveHealth());
        $url = '/api/payments/'.$this->payment->id.'/sponsorship';
        $before = $this->databaseRows();

        $this->getJson($url)->assertOk()->assertExactJson(['available' => true]);
        $this->assertSame($before, $this->databaseRows());

        config(['services.fee_delegation.mainnet_staging.approved_sender_addresses' => self::MERCHANT]);
        $this->getJson($url)->assertOk()->assertExactJson(['available' => false]);
        config(['services.fee_delegation.mainnet_staging.approved_sender_addresses' => self::SENDER]);

        config(['services.fee_delegation.mainnet_staging.pilot_payment_id' => $this->payment->id + 1]);
        $this->getJson($url)->assertOk()->assertExactJson(['available' => true]);
        config(['services.fee_delegation.mainnet_staging.pilot_payment_id' => $this->payment->id]);

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
        $this->getJson($url)->assertOk()->assertExactJson(['available' => false]);
        $this->assertDatabaseCount('payment_fee_delegation_attempts', 1);
    }

    public function test_live_store_checkout_authorizes_each_new_mainnet_payment(): void
    {
        $this->enableLiveGateForTest();
        config([
            'services.fee_delegation.self_hosted_mainnet.max_payment_jpy' => 5000,
        ]);
        $this->fakeReadOnlyServices(
            healthOverrides: $this->liveHealth([
                'pilot_policy' => ['max_payment_jpyc' => '5000'],
            ]),
            senderJpycAtomic: '5000000000000000000000'
        );
        $staff = Staff::findOrFail($this->payment->staff_id);
        Sanctum::actingAs($staff, ['payment:create']);

        $response = $this->postJson('/api/payments/create', ['amount' => 2375])
            ->assertOk();
        $payment = Payment::findOrFail($response->json('payment_id'));

        $this->assertNotSame($this->payment->id, $payment->id);
        $this->assertSame(2375, $payment->amount);
        $this->assertNotNull($payment->mainnet_authorized_at);
        $this->getJson('/api/payments/'.$payment->id)
            ->assertOk()
            ->assertJsonPath('mainnet_pilot.payment_id', $payment->id)
            ->assertJsonPath('mainnet_pilot.max_payment_jpyc', '5000');
        $this->getJson('/api/payments/'.$payment->id.'/sponsorship')
            ->assertOk()
            ->assertExactJson(['available' => true]);
        $expectedAmountWord = str_pad(
            gmp_strval(gmp_init('2375000000000000000000', 10), 16),
            64,
            '0',
            STR_PAD_LEFT
        );
        Http::assertSent(fn (Request $request): bool => ($request['method'] ?? null) === 'eth_estimateGas'
            && str_ends_with($request['params'][0]['data'] ?? '', $expectedAmountWord));
        $this->assertDatabaseCount('payment_fee_delegation_attempts', 0);
    }

    public function test_two_variable_mainnet_payments_keep_distinct_authorization_attempt_and_confirmation_evidence(): void
    {
        $this->enableLiveGateForTest();
        config([
            'services.fee_delegation.self_hosted_mainnet.max_payment_jpy' => 5000,
            'services.fee_delegation.self_hosted_mainnet.max_attempts_per_user' => 2,
            'services.fee_delegation.self_hosted_mainnet.max_attempts_per_store' => 2,
            'services.fee_delegation.self_hosted_mainnet.max_attempts_per_sender' => 2,
            'services.fee_delegation.self_hosted_mainnet.max_attempts_global' => 2,
            'services.fee_delegation.self_hosted_mainnet.daily_transaction_limit' => 2,
            'services.fee_delegation.self_hosted_mainnet.daily_kaia_budget' => '0.01',
        ]);

        $staff = Staff::findOrFail($this->payment->staff_id);
        Sanctum::actingAs($staff, ['payment:create']);
        $payments = collect([2375, 4999])->map(function (int $amount): Payment {
            $response = $this->postJson('/api/payments/create', ['amount' => $amount])
                ->assertOk();

            return Payment::findOrFail($response->json('payment_id'));
        })->values();

        $hashes = [
            '0x'.str_repeat('a', 64),
            '0x'.str_repeat('b', 64),
        ];
        $blockHashes = [
            '0x'.str_repeat('c', 64),
            '0x'.str_repeat('d', 64),
        ];
        $rawTransactions = [
            $this->mainnetSenderSignedTransaction($payments[0], '1'),
            $this->mainnetSenderSignedTransaction($payments[1], '2'),
        ];
        $byHash = [
            $hashes[0] => [$payments[0], $blockHashes[0], 16],
            $hashes[1] => [$payments[1], $blockHashes[1], 17],
        ];
        $feePayerRequests = [];
        $recoveries = 0;
        $confirmationRpcCalls = 0;

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (
            &$feePayerRequests,
            &$recoveries,
            &$confirmationRpcCalls,
            $hashes,
            $byHash
        ) {
            if ($request->url() === 'http://127.0.0.1:19000/api/signAsFeePayer') {
                $index = count($feePayerRequests);
                $feePayerRequests[] = [
                    'raw' => $request['userSignedTx']['raw'] ?? null,
                    'payment_id' => $request['paymentId'] ?? null,
                    'expires_at' => $request['expiresAt'] ?? null,
                    'authorization' => $request['paymentAuthorization'] ?? null,
                ];

                return Http::response([
                    'status' => true,
                    'data' => ['status' => 1, 'hash' => $hashes[$index]],
                ]);
            }

            $method = $request['method'] ?? null;
            if ($method === 'kaia_recoverFromTransaction') {
                $recoveries++;

                return Http::response([
                    'jsonrpc' => '2.0', 'id' => 1, 'result' => self::SENDER,
                ]);
            }

            $confirmationRpcCalls++;
            if ($method === 'eth_chainId') {
                return Http::response([
                    'jsonrpc' => '2.0', 'id' => 1, 'result' => '0x2019',
                ]);
            }

            if ($method === 'eth_getBlockByNumber') {
                $requestedBlock = $request['params'][0] ?? null;
                foreach ($byHash as $transactionHash => [$payment, $blockHash, $blockNumber]) {
                    if ($requestedBlock === '0x'.dechex($blockNumber)) {
                        return Http::response([
                            'jsonrpc' => '2.0',
                            'id' => 1,
                            'result' => [
                                'number' => $requestedBlock,
                                'hash' => $blockHash,
                                'timestamp' => '0x'.dechex($payment->created_at->addSecond()->timestamp),
                                'transactions' => [$transactionHash],
                            ],
                        ]);
                    }
                }
            }

            $hash = $request['params'][0] ?? null;
            [$payment, $blockHash, $blockNumber] = $byHash[$hash];
            $result = match ($method) {
                'eth_getTransactionByHash' => [
                    'hash' => $hash,
                    'blockNumber' => '0x'.dechex($blockNumber),
                    'blockHash' => $blockHash,
                    'from' => self::SENDER,
                ],
                'eth_getTransactionReceipt' => $this->mainnetReceipt(
                    $payment,
                    $hash,
                    $blockHash,
                    $blockNumber
                ),
                default => null,
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        });

        Sanctum::actingAs($this->user, ['payment:confirm']);
        foreach ($payments as $index => $payment) {
            $this->postJson('/api/payments/'.$payment->id.'/sponsor', [
                'sender_signed_tx' => $rawTransactions[$index],
            ])->assertOk()->assertExactJson([
                'transaction_hash' => $hashes[$index],
            ]);
        }

        $this->assertCount(2, $feePayerRequests);
        foreach ($payments as $index => $payment) {
            $attempt = PaymentFeeDelegationAttempt::query()
                ->where('payment_id', $payment->id)
                ->sole();
            $request = $feePayerRequests[$index];
            $this->assertSame((string) $payment->id, $request['payment_id']);
            $this->assertSame($rawTransactions[$index], $request['raw']);
            $this->assertSame($payment->expires_at->toIso8601String(), $request['expires_at']);
            $this->assertSame(hash_hmac(
                'sha256',
                MainnetPaymentAuthorization::canonical(
                    $payment->id,
                    $request['expires_at'],
                    $attempt->sender_tx_hash
                ),
                hex2bin(str_repeat('c', 64))
            ), $request['authorization']);
        }

        foreach ($payments as $index => $payment) {
            $this->postJson('/api/payments/'.$payment->id.'/confirm', [
                'tx_hash' => $hashes[$index],
            ])->assertOk()->assertExactJson(['success' => true]);
        }

        $attempts = PaymentFeeDelegationAttempt::query()
            ->whereIn('payment_id', $payments->pluck('id'))
            ->orderBy('payment_id')
            ->get();
        $this->assertCount(2, $attempts);
        $this->assertNotSame($attempts[0]->sender_tx_hash, $attempts[1]->sender_tx_hash);
        $this->assertNotSame($attempts[0]->request_fingerprint, $attempts[1]->request_fingerprint);
        foreach ($payments as $index => $payment) {
            $confirmed = $payment->fresh();
            $attempt = $attempts->firstWhere('payment_id', $payment->id);
            $this->assertSame('confirmed', $confirmed->status);
            $this->assertSame($hashes[$index], $confirmed->tx_hash);
            $this->assertSame($this->user->id, $confirmed->user_id);
            $this->assertSame($hashes[$index], $attempt->tx_hash);
            $this->assertSame('submitted', $attempt->broadcast_certainty);
            $this->assertSame('confirmed', $attempt->state);
            $this->assertNotNull($attempt->receipt_observed_at);
            $this->assertNotNull($attempt->resolved_at);
        }
        $this->assertSame(2, $recoveries);
        $this->assertSame(8, $confirmationRpcCalls);
    }

    private function enableLiveGateForTest(): void
    {
        config([
            'blockchain.mainnet_activation_release_capable' => true,
            'blockchain.profiles.kaia-mainnet.payment_execution_enabled' => true,
            'blockchain.profiles.kaia-mainnet.fee_delegation_execution_enabled' => true,
            'blockchain.payments_mainnet_enabled' => true,
            'blockchain.mainnet_fee_delegation_enabled' => true,
            'blockchain.mainnet_broadcast_enabled' => true,
            'services.fee_delegation.enabled' => true,
            'services.fee_delegation.url' => 'http://127.0.0.1:19000',
            'services.fee_delegation.api_key' => 'test-only-key',
            'services.fee_delegation.self_hosted_mainnet.enabled' => true,
            'services.fee_delegation.self_hosted_mainnet.kill_switch' => false,
        ]);
    }

    private function mainnetSenderSignedTransaction(Payment $payment, string $nonce): string
    {
        $input = hex2bin('a9059cbb')
            .str_repeat("\0", 12)
            .hex2bin(substr($payment->recipient_address, 2))
            .$this->uintWord($payment->atomic_amount);
        $fields = [
            $this->uintBytes($nonce),
            $this->uintBytes('25000000000'),
            $this->uintBytes('100000'),
            hex2bin(substr($payment->token_contract, 2)),
            '',
            hex2bin(substr(self::SENDER, 2)),
            $input,
            [[
                $this->uintBytes((string) (($payment->chain_id * 2) + 35)),
                hex2bin(str_repeat('a', 64)),
                hex2bin(str_repeat('2', 64)),
            ]],
        ];

        return '0x31'.bin2hex($this->rlpEncode($fields));
    }

    private function mainnetReceipt(
        Payment $payment,
        string $hash,
        string $blockHash,
        int $blockNumber
    ): array {
        return [
            'transactionHash' => $hash,
            'blockNumber' => '0x'.dechex($blockNumber),
            'blockHash' => $blockHash,
            'status' => '0x1',
            'logs' => [[
                'address' => $payment->token_contract,
                'topics' => [
                    '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
                    $this->addressTopic(self::SENDER),
                    $this->addressTopic($payment->recipient_address),
                ],
                'data' => '0x'.str_pad(
                    gmp_strval(gmp_init($payment->atomic_amount, 10), 16),
                    64,
                    '0',
                    STR_PAD_LEFT
                ),
                'logIndex' => '0x0',
                'removed' => false,
                'transactionHash' => $hash,
                'blockNumber' => '0x'.dechex($blockNumber),
                'blockHash' => $blockHash,
            ]],
        ];
    }

    private function addressTopic(string $address): string
    {
        return '0x'.str_pad(substr(strtolower($address), 2), 64, '0', STR_PAD_LEFT);
    }

    private function rlpEncode(array|string $value): string
    {
        if (is_array($value)) {
            $payload = '';
            foreach ($value as $item) {
                $payload .= $this->rlpEncode($item);
            }

            return $this->rlpPrefix(strlen($payload), 0xC0, 0xF7).$payload;
        }

        if (strlen($value) === 1 && ord($value[0]) <= 0x7F) {
            return $value;
        }

        return $this->rlpPrefix(strlen($value), 0x80, 0xB7).$value;
    }

    private function rlpPrefix(int $length, int $shortOffset, int $longOffset): string
    {
        if ($length <= 55) {
            return chr($shortOffset + $length);
        }
        $encodedLength = $this->uintBytes((string) $length);

        return chr($longOffset + strlen($encodedLength)).$encodedLength;
    }

    private function uintBytes(string $decimal): string
    {
        if ($decimal === '0') {
            return '';
        }
        $hex = gmp_strval(gmp_init($decimal, 10), 16);

        return hex2bin(strlen($hex) % 2 === 0 ? $hex : '0'.$hex);
    }

    private function uintWord(string $decimal): string
    {
        return str_pad($this->uintBytes($decimal), 32, "\0", STR_PAD_LEFT);
    }

    private function liveHealth(array $overrides = []): array
    {
        return array_replace_recursive([
            'status' => 'READY',
            'activation_release_capable' => true,
            'balance_wei' => '20000000000000000',
            'kill_switch' => 'INACTIVE',
            'execution' => 'ENABLED',
            'signing' => 'ENABLED',
            'broadcast' => 'ENABLED',
        ], $overrides);
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
            'mainnet_authorized_at' => now(),
        ], $current->databaseAttributes()));
        config([
            'services.fee_delegation.mainnet_staging.pilot_payment_id' => $this->payment->id,
        ]);

        return [$oldest->fresh(), $this->payment];
    }

    private function createConfirmedHistoryAndCurrent(): array
    {
        $confirmed = $this->payment;
        $transactionHash = '0x'.str_repeat('a', 64);
        $confirmed->forceFill([
            'status' => 'confirmed',
            'tx_hash' => $transactionHash,
            'observed_chain_id' => 8217,
            'confirmed_block_number' => 123,
            'confirmed_block_hash' => '0x'.str_repeat('b', 64),
            'receipt_status' => 1,
            'payer_address' => self::SENDER,
            'transfer_log_index' => 0,
            'chain_confirmed_at' => now(),
            'verified_at' => now(),
            'paid_at' => now(),
            'user_id' => $this->user->id,
            'reconciliation_status' => 'pending',
            'mainnet_authorized_at' => null,
        ])->saveQuietly();
        PaymentFeeDelegationAttempt::query()->create([
            'payment_id' => $confirmed->id,
            'requester_user_id' => $this->user->id,
            'network' => 'kaia-mainnet',
            'chain_id' => 8217,
            'provider' => 'self-hosted',
            'sender_address' => self::SENDER,
            'sender_tx_hash' => '0x'.str_repeat('c', 64),
            'tx_hash' => $transactionHash,
            'request_fingerprint' => str_repeat('d', 64),
            'state' => 'submitted',
            'broadcast_certainty' => null,
            'sender_nonce' => '0',
            'validated_at' => now(),
            'submitting_at' => now(),
            'submitted_at' => now(),
        ]);

        $snapshot = PaymentSnapshot::create(
            app(NetworkProfileRegistry::class)->get('kaia-mainnet'),
            self::MERCHANT,
            '1',
            now()->addHours(6),
            1
        );
        $this->payment = Payment::query()->create(array_merge([
            'store_id' => $confirmed->store_id,
            'staff_id' => $confirmed->staff_id,
            'amount' => 1,
            'status' => 'pending',
            'mainnet_authorized_at' => now(),
        ], $snapshot->databaseAttributes()));
        config([
            'services.fee_delegation.mainnet_staging.pilot_payment_id' => $this->payment->id,
        ]);

        return [$confirmed->fresh(), $this->payment];
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
            'mainnet_authorized_at' => now(),
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

    private function fakeReadOnlyServices(
        string $balance = '0.02',
        array $healthOverrides = [],
        int $healthStatus = 200,
        string $senderJpycAtomic = '2000000000000000000'
    ): void {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($balance, $healthOverrides, $healthStatus, $senderJpycAtomic) {
            if ($request->url() === 'http://127.0.0.1:19000/health') {
                return Http::response(array_replace_recursive([
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
                        'authorization_key_id' => 'sha256:c2f480d4dda9f452',
                        'authorization_mode' => 'hmac-sha256-v1',
                    ],
                ], $healthOverrides), $healthStatus);
            }

            $result = match ($request['method']) {
                'eth_chainId' => '0x2019',
                'eth_blockNumber' => '0x64',
                'eth_getBalance' => '0x'.gmp_strval(gmp_init(MainnetPilotLimits::kaiaToWei($balance), 10), 16),
                'eth_estimateGas' => '0x186a0',
                'eth_gasPrice' => '0x5d21dba00',
                'eth_getCode' => '0x6000',
                'eth_call' => match (true) {
                    $request['params'][0]['data'] === '0x313ce567' => '0x'.str_pad(dechex(18), 64, '0', STR_PAD_LEFT),
                    str_starts_with($request['params'][0]['data'] ?? '', '0x70a08231') => '0x'.str_pad(
                        gmp_strval(gmp_init($senderJpycAtomic, 10), 16),
                        64,
                        '0',
                        STR_PAD_LEFT
                    ),
                    default => $this->abiString('JPYC'),
                },
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
