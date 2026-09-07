<?php

namespace Tests\Feature;

use App\Blockchain\KairosManagedReadinessChecker;
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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KairosManagedLiveTestPreparationTest extends TestCase
{
    use RefreshDatabase;

    private const RPC_URL = 'https://rpc.example.test';

    private const ENDPOINT = 'https://fee-delegation-kairos.kaia.io';

    private const API_KEY = 'managed-readiness-test-api-key';

    private const TOKEN = '0xe7c3d8c9a439fede00d2600032d5db0be71c3c29';

    private const RECIPIENT = '0x70997970c51812dc3a010c7d01b50e0d17dc79c8';

    private const SENDER = '0xa2a8854b1802d8cd5de631e690817c253d6a9153';

    private const SIGNED_TRANSACTION = '0x31f8c50185066720b300830186a094e7c3d8c9a439fede00d2600032d5db0be71c3c298094a2a8854b1802d8cd5de631e690817c253d6a9153b844a9059cbb00000000000000000000000070997970c51812dc3a010c7d01b50e0d17dc79c80000000000000000000000000000000000000000000000000de0b6b3a7640000f847f8458207f6a052baf55055acf5989c5fcae851e263f7a99bc2403210ebb4e70bcebc7945bed8a0755eadf37734c3f1629836ed938782dd1e40d4ece67910a84c2dafe234fe8c6d';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'blockchain.network' => 'kairos',
            'blockchain.profiles.kairos.rpc_url' => self::RPC_URL,
            'blockchain.profiles.kairos.jpyc.contract' => self::TOKEN,
            'blockchain.profiles.kaia-mainnet.rpc_url' => self::RPC_URL,
            'blockchain.payments_mainnet_enabled' => false,
            'blockchain.mainnet_fee_delegation_enabled' => false,
            'blockchain.mainnet_broadcast_enabled' => false,
            'services.fee_delegation.enabled' => true,
            'services.fee_delegation.mode' => 'kaia-managed',
            'services.fee_delegation.url' => self::ENDPOINT,
            'services.fee_delegation.api_key' => self::API_KEY,
            'services.fee_delegation.kairos_managed_live_test_enabled' => true,
            'services.fee_delegation.kairos_managed_live_test_max_jpy' => 1,
        ]);

        Http::preventStrayRequests();

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = ON');
        }
    }

    public function test_valid_kairos_managed_readiness_is_read_only_and_redacted(): void
    {
        $payment = $this->createPayment();
        $this->selectPayment($payment);
        $this->fakeReadyRpc();

        $this->artisan('blockchain:kairos-managed-readiness')
            ->expectsOutputToContain('readiness=ready')
            ->expectsOutputToContain('provider_call=not_performed')
            ->doesntExpectOutputToContain(self::API_KEY)
            ->assertSuccessful();

        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request): bool => str_contains(
            $request->url(),
            'signAsFeePayer'
        ));
    }

    public function test_readiness_rejects_wrong_network(): void
    {
        config(['blockchain.network' => 'kaia-mainnet']);

        $this->assertReadinessFailure('active_network');
    }

    public function test_readiness_rejects_wrong_profile_chain_id(): void
    {
        config(['blockchain.profiles.kairos.chain_id' => 1002]);

        $this->assertReadinessFailure('profile_chain_id');
    }

    public function test_readiness_rejects_mainnet_endpoint(): void
    {
        config(['services.fee_delegation.url' => 'https://fee-delegation.kaia.io']);

        $this->assertReadinessFailure('managed_endpoint');
    }

    public function test_readiness_rejects_missing_api_key(): void
    {
        config(['services.fee_delegation.api_key' => null]);

        $this->assertReadinessFailure('backend_api_key');
    }

    public function test_readiness_rejects_disabled_sender_tx_hash_indexing(): void
    {
        $this->fakeReadyRpc(indexing: false);

        $report = app(KairosManagedReadinessChecker::class)->check();

        $this->assertFalse($report->ready);
        $this->assertFalse($report->checks['sender_tx_hash_indexing']['ready']);
    }

    public function test_readiness_rejects_an_open_mainnet_gate(): void
    {
        config(['blockchain.mainnet_broadcast_enabled' => true]);

        $this->assertReadinessFailure('mainnet_gates');
    }

    public function test_readiness_rejects_missing_or_excessive_test_limit(): void
    {
        foreach ([null, 2] as $limit) {
            config([
                'services.fee_delegation.kairos_managed_live_test_max_jpy' => $limit,
            ]);
            $this->assertReadinessFailure('test_maximum');
        }
    }

    public function test_valid_dry_run_outputs_safe_metadata_without_writing_or_broadcasting(): void
    {
        $payment = $this->createPayment();
        $this->selectPayment($payment);
        $this->fakeReadyRpc(withSenderRecovery: true);

        $this->artisan('payments:prepare-managed-kairos-test', [
            'payment' => $payment->id,
            '--sender-signed-tx' => self::SIGNED_TRANSACTION,
        ])
            ->expectsOutputToContain('dry_run=ready')
            ->expectsOutputToContain('attempt_written=no')
            ->expectsOutputToContain('broadcast=not_performed')
            ->doesntExpectOutputToContain(self::API_KEY)
            ->assertSuccessful();

        $this->assertDatabaseCount('payment_fee_delegation_attempts', 0);
        $this->assertSame('pending', $payment->fresh()->status);
        Http::assertSentCount(4);
        Http::assertNotSent(fn (Request $request): bool => str_contains(
            $request->url(),
            'signAsFeePayer'
        ));
    }

    public function test_dry_run_rejects_amount_above_test_maximum(): void
    {
        $payment = $this->createPayment(amount: 2);
        $this->selectPayment($payment);

        $this->artisan('payments:prepare-managed-kairos-test', [
            'payment' => $payment->id,
            '--sender-signed-tx' => self::SIGNED_TRANSACTION,
        ])->assertFailed();

        Http::assertNothingSent();
    }

    public function test_dry_run_rejects_non_kairos_payment(): void
    {
        $payment = $this->createPayment(network: 'kaia-mainnet');
        $this->selectPayment($payment);

        $this->artisan('payments:prepare-managed-kairos-test', [
            'payment' => $payment->id,
            '--sender-signed-tx' => self::SIGNED_TRANSACTION,
        ])->assertFailed();

        Http::assertNothingSent();
    }

    public function test_dry_run_rejects_incomplete_snapshot(): void
    {
        $payment = $this->createPayment(incomplete: true);
        $this->selectPayment($payment);

        $this->artisan('payments:prepare-managed-kairos-test', [
            'payment' => $payment->id,
            '--sender-signed-tx' => self::SIGNED_TRANSACTION,
        ])->assertFailed();

        Http::assertNothingSent();
    }

    public function test_existing_unknown_or_submitted_attempt_blocks_dry_run(): void
    {
        foreach (['unknown_submission', 'submitted'] as $index => $state) {
            $payment = $this->createPayment(suffix: "attempt-{$state}");
            $this->selectPayment($payment);
            $this->createAttempt($payment, $state, $index + 1);

            $this->artisan('payments:prepare-managed-kairos-test', [
                'payment' => $payment->id,
                '--sender-signed-tx' => self::SIGNED_TRANSACTION,
            ])->assertFailed();
        }

        Http::assertNothingSent();
    }

    public function test_actual_managed_path_requires_live_gate_and_selected_payment(): void
    {
        $payment = $this->createPayment();
        $this->selectPayment($payment);
        config([
            'services.fee_delegation.kairos_managed_live_test_enabled' => false,
        ]);
        Http::fake(fn () => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => self::SENDER,
        ]));

        $user = User::create([
            'name' => 'Gate Test User',
            'email' => 'gate@example.test',
            'password' => 'not-used',
        ]);

        Sanctum::actingAs($user, ['payment:confirm']);

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => self::SIGNED_TRANSACTION,
        ])
            ->assertInternalServerError();

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_contains(
            $request->url(),
            'signAsFeePayer'
        ));
        $this->assertDatabaseCount('payment_fee_delegation_attempts', 0);
    }

    private function assertReadinessFailure(string $check): void
    {
        Http::fake();
        $report = app(KairosManagedReadinessChecker::class)->check();

        $this->assertFalse($report->ready);
        $this->assertFalse($report->checks[$check]['ready']);
        Http::assertNotSent(fn (Request $request): bool => str_contains(
            $request->url(),
            'signAsFeePayer'
        ));
    }

    private function fakeReadyRpc(
        bool $indexing = true,
        bool $withSenderRecovery = false
    ): void {
        Http::fake(function (Request $request) use ($indexing, $withSenderRecovery) {
            $result = match ($request['method']) {
                'kaia_chainId' => '0x3e9',
                'kaia_isSenderTxHashIndexingEnabled' => $indexing,
                'kaia_blockNumber' => '0x10',
                'kaia_recoverFromTransaction' => $withSenderRecovery
                    ? self::SENDER
                    : null,
                default => null,
            };

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => $result,
            ]);
        });
    }

    private function selectPayment(Payment $payment): void
    {
        config([
            'services.fee_delegation.kairos_managed_live_test_payment_id' => $payment->id,
        ]);
    }

    private function createPayment(
        int $amount = 1,
        string $network = 'kairos',
        bool $incomplete = false,
        string $suffix = 'default'
    ): Payment {
        $store = Store::create([
            'store_code' => "managed-test-store-{$suffix}",
            'store_pin' => 'managed-test-pin',
            'name' => 'Managed Test Store',
        ]);
        $staff = Staff::create([
            'store_id' => $store->id,
            'staff_id' => "managed-test-staff-{$suffix}",
            'name' => 'Managed Test Staff',
            'pin' => 'managed-test-staff-pin',
            'role' => 'staff',
        ]);
        Wallet::create([
            'store_id' => $store->id,
            'address' => self::RECIPIENT,
            'network' => 'kairos',
        ]);

        $base = [
            'store_id' => $store->id,
            'staff_id' => $staff->id,
            'amount' => $amount,
            'status' => 'pending',
            'expires_at' => $incomplete ? null : now()->addMinutes(10),
        ];

        if ($incomplete) {
            return Payment::create($base);
        }

        $profile = app(NetworkProfileRegistry::class)->get($network);
        $snapshot = PaymentSnapshot::create(
            $profile,
            self::RECIPIENT,
            $amount,
            $base['expires_at']
        );

        return Payment::create(array_merge(
            $base,
            $snapshot->databaseAttributes()
        ));
    }

    private function createAttempt(
        Payment $payment,
        string $state,
        int $nonce
    ): void {
        $user = User::create([
            'name' => 'Attempt Test User',
            'email' => "attempt-{$nonce}@example.test",
            'password' => 'not-used',
        ]);
        PaymentFeeDelegationAttempt::create([
            'payment_id' => $payment->id,
            'requester_user_id' => $user->id,
            'network' => 'kairos',
            'chain_id' => 1001,
            'provider' => 'kaia-managed',
            'sender_address' => self::SENDER,
            'sender_tx_hash' => '0x'.str_repeat((string) $nonce, 64),
            'request_fingerprint' => str_repeat((string) $nonce, 64),
            'state' => $state,
            'sender_nonce' => (string) $nonce,
        ]);
    }
}
