<?php

namespace Tests\Feature;

use App\Blockchain\MainnetReadinessChecker;
use App\Blockchain\NetworkProfileRegistry;
use App\Models\Payment;
use App\Models\PaymentFeeDelegationAttempt;
use App\Models\Staff;
use App\Models\Store;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\PaymentSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ConfirmedPaymentAttributes;
use Tests\TestCase;

final class ReplaceExpiredMainnetPilotPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const MERCHANT = '0x1111111111111111111111111111111111111111';

    private const FEE_PAYER = '0x2222222222222222222222222222222222222222';

    private const SENDER = '0x3333333333333333333333333333333333333333';

    private Payment $payment;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-10T00:00:00Z');
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = ON');
        }
        $this->app['env'] = 'mainnet-staging';
        config([
            'blockchain.network' => 'kaia-mainnet',
            'blockchain.payment_expiration_seconds' => 21600,
            'blockchain.payments_mainnet_enabled' => false,
            'blockchain.mainnet_fee_delegation_enabled' => false,
            'blockchain.mainnet_broadcast_enabled' => false,
            'services.fee_delegation.mode' => 'self-hosted',
            'services.fee_delegation.self_hosted_mainnet.enabled' => false,
            'services.fee_delegation.self_hosted_mainnet.kill_switch' => true,
        ]);

        $store = Store::query()->create([
            'store_code' => 'replacement-pilot',
            'store_pin' => 'test-only-hash',
            'name' => 'Replacement Pilot',
        ]);
        $staff = Staff::query()->create([
            'store_id' => $store->id,
            'staff_id' => 'replacement-staff',
            'name' => 'Replacement Staff',
            'pin' => 'test-only-hash',
            'role' => 'staff',
        ]);
        $this->user = User::query()->create([
            'name' => 'Replacement User',
            'email' => 'replacement@example.test',
            'password' => 'test-only-hash',
            'wallet_address' => self::SENDER,
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
            'approved_sender_addresses' => self::SENDER,
            'allow_cross_environment_reuse' => false,
            'pilot_payment_id' => null,
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
        config([
            'services.fee_delegation.mainnet_staging.pilot_payment_id' => $this->payment->id,
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_active_payment_cannot_be_replaced(): void
    {
        $this->artisan('mainnet:replace-expired-pilot-payment')
            ->expectsOutputToContain('expired_pilot_history_invalid')
            ->assertFailed();

        $this->assertDatabaseCount('payments', 1);
        Http::assertNothingSent();
    }

    public function test_expired_unused_payment_is_preserved_and_replaced_exactly_once(): void
    {
        $this->expirePayment();
        $old = (array) DB::table('payments')->where('id', $this->payment->id)->first();

        $this->assertSame(0, Artisan::call('mainnet:replace-expired-pilot-payment'));

        $this->assertDatabaseCount('payments', 2);
        $this->assertEquals(
            $old,
            (array) DB::table('payments')->where('id', $this->payment->id)->first()
        );
        $new = Payment::query()->whereKeyNot($this->payment->id)->sole();
        $output = Artisan::output();
        $this->assertStringContainsString('old_payment_id='.$this->payment->id, $output);
        $this->assertStringContainsString('new_payment_id='.$new->id, $output);
        $this->assertStringContainsString('history_count=1', $output);
        $this->assertStringContainsString('MAINNET_PILOT_PAYMENT_ID='.$new->id, $output);
        $snapshot = PaymentSnapshot::fromRecord($new);
        $this->assertSame('pending', $new->status);
        $this->assertNull($new->user_id);
        $this->assertNull($new->tx_hash);
        $this->assertSame('kaia-mainnet', $snapshot->network);
        $this->assertSame(8217, $snapshot->chainId);
        $this->assertSame(MainnetReadinessChecker::JPYC_CONTRACT, $snapshot->tokenContract);
        $this->assertSame('JPYC', $snapshot->tokenSymbol);
        $this->assertSame(18, $snapshot->tokenDecimals);
        $this->assertSame(self::MERCHANT, $snapshot->recipientAddress);
        $this->assertSame('1', $snapshot->displayAmount);
        $this->assertSame('1000000000000000000', $snapshot->atomicAmount);
        $this->assertTrue($snapshot->expiresAt->equalTo(now()->addSeconds(21600)));
        $this->assertDatabaseCount('payment_fee_delegation_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_attempt_bearing_payment_cannot_be_replaced(): void
    {
        $this->expirePayment();
        $this->createAttempt();

        $this->artisan('mainnet:replace-expired-pilot-payment')
            ->expectsOutputToContain('fee_delegation_attempt_exists')
            ->assertFailed();

        $this->assertDatabaseCount('payments', 1);
        Http::assertNothingSent();
    }

    public function test_tx_or_confirmation_evidence_cannot_be_replaced(): void
    {
        foreach (['tx_hash', 'observed_chain_id'] as $field) {
            $this->expirePayment();
            $evidence = $field === 'tx_hash'
                ? ['tx_hash' => '0x'.str_repeat('a', 64)]
                : ConfirmedPaymentAttributes::forChain(8217);
            DB::table('payments')->where('id', $this->payment->id)->update($evidence);

            $this->artisan('mainnet:replace-expired-pilot-payment')
                ->expectsOutputToContain('expired_pilot_history_invalid')
                ->assertFailed();
            DB::table('payments')->where('id', $this->payment->id)->update(array_fill_keys(array_keys($evidence), null));
        }

        $this->assertDatabaseCount('payments', 1);
        Http::assertNothingSent();
    }

    public function test_confirmed_or_failed_payment_cannot_be_replaced(): void
    {
        foreach (['confirmed', 'failed'] as $status) {
            $this->expirePayment();
            $evidence = $status === 'confirmed' ? ConfirmedPaymentAttributes::forChain(8217) : [];
            DB::table('payments')->where('id', $this->payment->id)->update(['status' => $status] + $evidence);

            $this->artisan('mainnet:replace-expired-pilot-payment')
                ->expectsOutputToContain('expired_pilot_history_invalid')
                ->assertFailed();
            DB::table('payments')->where('id', $this->payment->id)->update(['status' => 'pending'] + array_fill_keys(array_keys($evidence), null));
        }

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_wrong_recipient_amount_or_network_is_rejected(): void
    {
        foreach ([
            ['recipient_address', self::FEE_PAYER],
            ['display_amount', 2],
            ['network', 'kairos'],
            ['token_contract', self::FEE_PAYER],
        ] as [$field, $value]) {
            $this->expirePayment();
            $original = $this->payment->getRawOriginal($field);
            DB::table('payments')->where('id', $this->payment->id)->update([
                $field => $value,
            ]);

            $this->artisan('mainnet:replace-expired-pilot-payment')
                ->expectsOutputToContain('expired_pilot_history_invalid')
                ->assertFailed();
            DB::table('payments')->where('id', $this->payment->id)->update([
                $field => $original,
            ]);
        }

        $this->assertDatabaseCount('payments', 1);
        Http::assertNothingSent();
    }

    public function test_double_replacement_and_wrong_configured_id_are_rejected(): void
    {
        $this->expirePayment();
        config(['services.fee_delegation.mainnet_staging.pilot_payment_id' => 999]);
        $this->artisan('mainnet:replace-expired-pilot-payment')
            ->expectsOutputToContain('pilot_payment_id_does_not_reference_latest_expired_payment')
            ->assertFailed();

        config([
            'services.fee_delegation.mainnet_staging.pilot_payment_id' => $this->payment->id,
        ]);
        $this->artisan('mainnet:replace-expired-pilot-payment')->assertSuccessful();
        $this->artisan('mainnet:replace-expired-pilot-payment')
            ->expectsOutputToContain('pilot_payment_id_does_not_reference_latest_expired_payment')
            ->assertFailed();

        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('payment_fee_delegation_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_two_expired_payments_create_third_and_preserve_all_history(): void
    {
        $this->createExpiredHistory(2);
        $before = $this->paymentRows();

        $oldId = $this->payment->id;
        $this->assertSame(0, Artisan::call('mainnet:replace-expired-pilot-payment'));
        $new = Payment::query()->orderByDesc('id')->firstOrFail();
        $output = Artisan::output();
        $this->assertStringContainsString('old_payment_id='.$oldId, $output);
        $this->assertStringContainsString('new_payment_id='.$new->id, $output);
        $this->assertStringContainsString('history_count=2', $output);
        $this->assertStringContainsString('MAINNET_PILOT_PAYMENT_ID='.$new->id, $output);

        $this->assertSame($before, array_slice($this->paymentRows(), 0, 2));
        $this->assertDatabaseCount('payments', 3);
        $this->assertSame('pending', $new->status);
        Http::assertNothingSent();
    }

    public function test_three_expired_payments_create_next_and_preserve_every_byte(): void
    {
        $this->createExpiredHistory(3);
        $before = $this->paymentRows();

        $oldId = $this->payment->id;
        $this->assertSame(0, Artisan::call('mainnet:replace-expired-pilot-payment'));
        $new = Payment::query()->orderByDesc('id')->firstOrFail();
        $output = Artisan::output();
        $this->assertStringContainsString('old_payment_id='.$oldId, $output);
        $this->assertStringContainsString('new_payment_id='.$new->id, $output);
        $this->assertStringContainsString('history_count=3', $output);

        $this->assertSame($before, array_slice($this->paymentRows(), 0, 3));
        $this->assertDatabaseCount('payments', 4);
        $this->assertDatabaseCount('payment_fee_delegation_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_latest_configured_id_is_required_for_multiple_history_rows(): void
    {
        $this->createExpiredHistory(2);

        config(['services.fee_delegation.mainnet_staging.pilot_payment_id' => Payment::query()->orderBy('id')->value('id')]);
        $this->artisan('mainnet:replace-expired-pilot-payment')
            ->expectsOutputToContain('pilot_payment_id_does_not_reference_latest_expired_payment')
            ->assertFailed();
        config(['services.fee_delegation.mainnet_staging.pilot_payment_id' => null]);
        $this->artisan('mainnet:replace-expired-pilot-payment')
            ->expectsOutputToContain('latest_pilot_payment_id_required')
            ->assertFailed();

        $this->assertDatabaseCount('payments', 2);
    }

    public function test_invalid_older_history_blocks_replacement(): void
    {
        foreach (['active', 'attempt', 'tx', 'snapshot'] as $case) {
            $this->createExpiredHistory(2);
            $oldId = Payment::query()->orderBy('id')->value('id');
            $old = Payment::query()->findOrFail($oldId);
            if ($case === 'active') {
                DB::table('payments')->where('id', $oldId)->update([
                    'expires_at' => now()->addSecond(),
                ]);
            } elseif ($case === 'attempt') {
                $this->createAttempt($old);
            } elseif ($case === 'tx') {
                DB::table('payments')->where('id', $oldId)->update([
                    'tx_hash' => '0x'.str_repeat('a', 64),
                ]);
            } else {
                DB::table('payments')->where('id', $oldId)->update([
                    'recipient_address' => self::FEE_PAYER,
                ]);
            }

            $this->artisan('mainnet:replace-expired-pilot-payment')->assertFailed();
            $this->assertDatabaseCount('payments', 2);

            PaymentFeeDelegationAttempt::query()->delete();
            Payment::query()->where('id', '>', $oldId)->delete();
            DB::table('payments')->where('id', $oldId)->update([
                'expires_at' => now()->subSecond(),
                'tx_hash' => null,
                'recipient_address' => self::MERCHANT,
            ]);
            $this->payment = Payment::query()->findOrFail($oldId);
        }

        Http::assertNothingSent();
    }

    public function test_environment_database_and_gate_guards_remain_fail_closed(): void
    {
        $this->expirePayment();
        $originalEnvironment = $this->app->environment();
        $this->app['env'] = 'production';
        $this->artisan('mainnet:replace-expired-pilot-payment')->assertFailed();
        $this->app['env'] = $originalEnvironment;

        config(['services.fee_delegation.mainnet_staging.database_identifier' => 'wrong']);
        $this->artisan('mainnet:replace-expired-pilot-payment')->assertFailed();
        config([
            'services.fee_delegation.mainnet_staging.database_identifier' => DB::connection()->getDatabaseName(),
        ]);
        config([
            'services.fee_delegation.mainnet_staging.kairos_database_identifier' => DB::connection()->getDatabaseName(),
        ]);
        $this->artisan('mainnet:replace-expired-pilot-payment')->assertFailed();
        config([
            'services.fee_delegation.mainnet_staging.kairos_database_identifier' => 'different_kairos_database',
        ]);

        foreach ([
            'blockchain.payments_mainnet_enabled',
            'blockchain.mainnet_fee_delegation_enabled',
            'blockchain.mainnet_broadcast_enabled',
            'services.fee_delegation.self_hosted_mainnet.enabled',
        ] as $gate) {
            config([$gate => true]);
            $this->artisan('mainnet:replace-expired-pilot-payment')->assertFailed();
            config([$gate => false]);
        }
        config(['services.fee_delegation.self_hosted_mainnet.kill_switch' => false]);
        $this->artisan('mainnet:replace-expired-pilot-payment')->assertFailed();

        $this->assertDatabaseCount('payments', 1);
        Http::assertNothingSent();
    }

    public function test_command_has_no_environment_file_or_network_write_path(): void
    {
        $source = file_get_contents(
            app_path('Console/Commands/ReplaceExpiredMainnetPilotPayment.php')
        );
        $this->assertIsString($source);
        foreach ([
            'file_put_contents',
            'fwrite(',
            'Filesystem',
            'Dotenv',
            'Http::',
            'signAsFeePayer',
            'sendRawTransaction',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    private function expirePayment(): void
    {
        DB::table('payments')->where('id', $this->payment->id)->update([
            'expires_at' => now()->subSecond(),
        ]);
        $this->payment->refresh();
    }

    private function createAttempt(?Payment $payment = null): void
    {
        $payment ??= $this->payment;
        PaymentFeeDelegationAttempt::query()->create([
            'payment_id' => $payment->id,
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
    }

    private function createExpiredHistory(int $count): void
    {
        $this->expirePayment();
        while (Payment::query()->count() < $count) {
            $snapshot = PaymentSnapshot::create(
                app(NetworkProfileRegistry::class)->get('kaia-mainnet'),
                self::MERCHANT,
                '1',
                now()->subSecond(),
                1
            );
            $this->payment = Payment::query()->create(array_merge([
                'store_id' => $this->payment->store_id,
                'staff_id' => $this->payment->staff_id,
                'amount' => 1,
                'status' => 'pending',
            ], $snapshot->databaseAttributes()));
        }
        config([
            'services.fee_delegation.mainnet_staging.pilot_payment_id' => $this->payment->id,
        ]);
    }

    private function paymentRows(): array
    {
        return DB::table('payments')
            ->orderBy('id')
            ->get()
            ->map(fn ($payment): array => (array) $payment)
            ->all();
    }
}
