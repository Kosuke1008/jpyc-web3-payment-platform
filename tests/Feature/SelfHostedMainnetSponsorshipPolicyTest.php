<?php

namespace Tests\Feature;

use App\Blockchain\NetworkProfileRegistry;
use App\Models\Payment;
use App\Models\PaymentFeeDelegationAttempt;
use App\Models\Staff;
use App\Models\Store;
use App\Models\User;
use App\Payments\PaymentSnapshot;
use App\Services\Payments\PaymentSponsorshipException;
use App\Services\Payments\SelfHostedMainnetSponsorshipPolicy;
use App\Services\Payments\SponsoredTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SelfHostedMainnetSponsorshipPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'blockchain.profiles.kaia-mainnet.rpc_url' => 'https://mainnet.example.test',
            'blockchain.payments_mainnet_enabled' => true,
            'blockchain.mainnet_fee_delegation_enabled' => true,
            'blockchain.mainnet_broadcast_enabled' => true,
            'services.fee_delegation.self_hosted_mainnet' => [
                'enabled' => true,
                'kill_switch' => false,
                'max_payment_jpy' => 1,
                'max_gas' => 150000,
                'max_gas_price_wei' => '750000000000',
                'rate_window_seconds' => 3600,
                'max_attempts_per_user' => 1,
                'max_attempts_per_store' => 1,
                'max_attempts_per_sender' => 1,
                'max_attempts_global' => 5,
                'daily_transaction_limit' => 10,
                'daily_kaia_budget' => '1',
                'minimum_reserve_kaia' => '0.2',
            ],
            'services.fee_delegation.mainnet_staging' => [
                'merchant_address' => '0x2222222222222222222222222222222222222222',
                'kairos_merchant_address' => '0x4444444444444444444444444444444444444444',
                'fee_payer_address' => '0x5555555555555555555555555555555555555555',
                'kairos_fee_payer_address' => '0x6666666666666666666666666666666666666666',
                'approved_user_ids' => '1',
                'approved_sender_addresses' => '0x3333333333333333333333333333333333333333',
                'allow_cross_environment_reuse' => false,
            ],
        ]);

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = ON');
        }
    }

    public function test_valid_policy_runs_once_under_explicit_future_gates(): void
    {
        [$payment, $user] = $this->payment();
        $calls = 0;

        $result = app(SelfHostedMainnetSponsorshipPolicy::class)->run(
            $payment,
            PaymentSnapshot::fromRecord($payment),
            $this->transfer(),
            'self-hosted',
            $user->id,
            function () use (&$calls): string {
                $calls++;

                return 'reserved';
            }
        );

        $this->assertSame('reserved', $result);
        $this->assertSame(1, $calls);
    }

    public function test_kill_switch_and_closed_mainnet_gate_fail_before_operation(): void
    {
        [$payment, $user] = $this->payment();

        foreach ([
            ['services.fee_delegation.self_hosted_mainnet.kill_switch', true],
            ['blockchain.mainnet_broadcast_enabled', false],
        ] as [$key, $value]) {
            config([$key => $value]);
            $called = false;

            try {
                app(SelfHostedMainnetSponsorshipPolicy::class)->run(
                    $payment,
                    PaymentSnapshot::fromRecord($payment),
                    $this->transfer(),
                    'self-hosted',
                    $user->id,
                    function () use (&$called): void {
                        $called = true;
                    }
                );
                $this->fail('Expected policy rejection.');
            } catch (PaymentSponsorshipException) {
                $this->assertFalse($called);
            }

            config([
                'services.fee_delegation.self_hosted_mainnet.kill_switch' => false,
                'blockchain.mainnet_broadcast_enabled' => true,
            ]);
        }
    }

    public function test_payment_amount_and_gas_limits_are_enforced(): void
    {
        foreach (
            [
                [2, '100000', '25000000000'],
                [1, '150001', '25000000000'],
                [1, '100000', '750000000001'],
            ] as [$amount, $gas, $gasPrice]
        ) {
            [$payment, $user] = $this->payment(amount: $amount);

            try {
                app(SelfHostedMainnetSponsorshipPolicy::class)->run(
                    $payment,
                    PaymentSnapshot::fromRecord($payment),
                    $this->transfer(gas: $gas, gasPrice: $gasPrice),
                    'self-hosted',
                    $user->id,
                    fn () => null
                );
                $this->fail('Expected amount or gas policy rejection.');
            } catch (PaymentSponsorshipException $exception) {
                $this->assertSame(
                    PaymentSponsorshipException::POLICY_REJECTED,
                    $exception->reason
                );
            }
        }
    }

    public function test_database_attempts_enforce_rate_limit_across_policy_instances(): void
    {
        [$payment, $user] = $this->payment();
        $this->attempt($payment, $user);

        $this->expectException(PaymentSponsorshipException::class);
        app(SelfHostedMainnetSponsorshipPolicy::class)->run(
            $payment,
            PaymentSnapshot::fromRecord($payment),
            $this->transfer(),
            'self-hosted',
            $user->id,
            fn () => null
        );
    }

    public function test_daily_kaia_budget_is_enforced_conservatively(): void
    {
        [$payment, $user] = $this->payment();
        config([
            'services.fee_delegation.self_hosted_mainnet.daily_kaia_budget' => '0.000001',
        ]);

        $this->expectException(PaymentSponsorshipException::class);
        app(SelfHostedMainnetSponsorshipPolicy::class)->run(
            $payment,
            PaymentSnapshot::fromRecord($payment),
            $this->transfer(),
            'self-hosted',
            $user->id,
            fn () => null
        );
    }

    /** @return array{Payment, User} */
    private function payment(int $amount = 1): array
    {
        $suffix = uniqid('', true);
        $store = Store::create([
            'store_code' => "mainnet-policy-store-{$suffix}",
            'store_pin' => 'policy-pin',
            'name' => 'Mainnet Policy Store',
        ]);
        $staff = Staff::create([
            'store_id' => $store->id,
            'staff_id' => "mainnet-policy-staff-{$suffix}",
            'name' => 'Mainnet Policy Staff',
            'pin' => 'policy-pin',
            'role' => 'staff',
        ]);
        $user = User::create([
            'name' => 'Mainnet Policy User',
            'email' => "policy-{$suffix}@example.test",
            'password' => 'not-used',
        ]);
        $expiresAt = now()->addMinutes(10);
        $snapshot = PaymentSnapshot::create(
            app(NetworkProfileRegistry::class)->get('kaia-mainnet'),
            '0x2222222222222222222222222222222222222222',
            $amount,
            $expiresAt
        );
        $payment = Payment::create(array_merge([
            'store_id' => $store->id,
            'staff_id' => $staff->id,
            'amount' => $amount,
            'status' => 'pending',
            'expires_at' => $expiresAt,
        ], $snapshot->databaseAttributes()));

        return [$payment, $user];
    }

    private function transfer(
        string $gas = '100000',
        string $gasPrice = '25000000000'
    ): SponsoredTransfer {
        return new SponsoredTransfer(
            chainId: 8217,
            sender: '0x3333333333333333333333333333333333333333',
            tokenContract: '0xe7c3d8c9a439fede00d2600032d5db0be71c3c29',
            recipient: '0x2222222222222222222222222222222222222222',
            atomicAmount: '1000000000000000000',
            gasLimit: $gas,
            gasPrice: $gasPrice,
            nonce: '1'
        );
    }

    private function attempt(Payment $payment, User $user): void
    {
        PaymentFeeDelegationAttempt::create([
            'payment_id' => $payment->id,
            'requester_user_id' => $user->id,
            'network' => 'kaia-mainnet',
            'chain_id' => 8217,
            'provider' => 'self-hosted',
            'sender_address' => '0x3333333333333333333333333333333333333333',
            'sender_tx_hash' => '0x'.str_repeat('a', 64),
            'request_fingerprint' => str_repeat('b', 64),
            'state' => 'unknown_submission',
            'sender_nonce' => '1',
        ]);
    }
}
