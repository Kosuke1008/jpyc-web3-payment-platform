<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Staff;
use App\Models\Store;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

class PaymentCreateTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '0xe7c3d8c9a439fede00d2600032d5db0be71c3c29';

    private const RECIPIENT = '0x2222222222222222222222222222222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'blockchain.network' => 'kairos',
            'blockchain.payment_max_jpy' => 100000,
            'blockchain.payment_expiration_seconds' => 600,
            'blockchain.profiles.kairos.chain_id' => 1001,
            'blockchain.profiles.kairos.jpyc.contract' => self::TOKEN,
            'blockchain.profiles.kairos.jpyc.symbol' => 'JPYC',
            'blockchain.profiles.kairos.jpyc.decimals' => 18,
        ]);
    }

    public function test_authorized_staff_can_create_a_payment(): void
    {
        [$store, $staff] = $this->createStaff();
        Sanctum::actingAs($staff, ['payment:create']);

        $response = $this->postJson('/api/payments/create', [
            'amount' => 1_000,
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure([
                'payment_id',
                'amount',
                'pay_url',
                'qr_code',
                'qr_code_base64',
                'expires_at',
                'network',
                'chain_name',
                'recipient_address',
                'token_symbol',
            ])
            ->assertJsonPath('amount', 1_000)
            ->assertJsonPath('network', 'kairos')
            ->assertJsonPath('recipient_address', self::RECIPIENT)
            ->assertJsonPath('token_symbol', 'JPYC');

        $paymentId = $response->json('payment_id');
        $payment = Payment::findOrFail($paymentId);

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'store_id' => $store->id,
            'staff_id' => $staff->id,
            'amount' => 1_000,
            'status' => 'pending',
            'network' => 'kairos',
            'network_profile_version' => 1,
            'chain_id' => 1001,
            'token_contract' => self::TOKEN,
            'token_symbol' => 'JPYC',
            'token_decimals' => 18,
            'recipient_address' => self::RECIPIENT,
            'display_amount' => '1000',
            'atomic_amount' => '1000000000000000000000',
            'expires_at' => $payment->expires_at,
        ]);
        $this->assertNotNull($payment->expires_at);
        $this->assertSame(
            config('app.url').'/pay/'.$paymentId,
            $response->json('pay_url')
        );
        $this->assertNotSame('', $response->json('qr_code'));
        $this->assertSame(
            $response->json('qr_code'),
            base64_decode($response->json('qr_code_base64'), true)
        );
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/payments/create', [
            'amount' => 1_000,
        ])->assertUnauthorized();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_invalid_amount_is_rejected(): void
    {
        [, $staff] = $this->createStaff();
        Sanctum::actingAs($staff, ['payment:create']);

        foreach ([
            null,
            0,
            -1,
            100_001,
            'invalid',
            '1.1',
            '0.5',
            '1e3',
            '100.00',
            '01',
            1.1,
        ] as $amount) {
            $this->postJson('/api/payments/create', [
                'amount' => $amount,
            ])->assertUnprocessable();
        }

        $this->call(
            'POST',
            '/api/payments/create',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: '{"amount":1000.0}'
        )->assertUnprocessable();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_missing_or_wrong_network_wallet_fails_closed(): void
    {
        [$store, $staff] = $this->createStaff(createWallet: false);
        Sanctum::actingAs($staff, ['payment:create']);

        $this->postJson('/api/payments/create', ['amount' => 100])
            ->assertInternalServerError();

        Wallet::create([
            'store_id' => $store->id,
            'address' => self::RECIPIENT,
            'network' => 'sepolia',
        ]);

        $this->postJson('/api/payments/create', ['amount' => 100])
            ->assertInternalServerError();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_snapshot_fields_are_immutable_after_creation(): void
    {
        [, $staff] = $this->createStaff();
        Sanctum::actingAs($staff, ['payment:create']);
        $paymentId = $this->postJson('/api/payments/create', ['amount' => 100])
            ->assertOk()
            ->json('payment_id');
        $payment = Payment::findOrFail($paymentId);
        $payment->recipient_address = '0x9999999999999999999999999999999999999999';

        $this->expectException(LogicException::class);
        $payment->save();
    }

    public function test_persistence_failure_returns_a_generic_error_without_database_details(): void
    {
        [, $staff] = $this->createStaff();
        Sanctum::actingAs($staff, ['payment:create']);
        Payment::creating(function (Payment $payment): void {
            if ($payment->amount === 777) {
                throw new \RuntimeException(
                    'SQLSTATE secret database detail must not escape'
                );
            }
        });

        $response = $this->postJson('/api/payments/create', ['amount' => 777])
            ->assertServiceUnavailable()
            ->assertExactJson([
                'error' => 'Payment creation failed safely',
            ]);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_mainnet_creation_fails_closed_without_authorized_configuration(): void
    {
        [, $staff, $wallet] = $this->createStaff();
        Sanctum::actingAs($staff, ['payment:create']);
        $wallet->network = 'kaia-mainnet';
        $wallet->save();
        config(['blockchain.network' => 'kaia-mainnet']);

        $this->postJson('/api/payments/create', ['amount' => 100])
            ->assertServiceUnavailable()
            ->assertExactJson([
                'error' => 'Mainnet payment authorization unavailable',
            ]);

        $this->assertDatabaseCount('payments', 0);
    }

    private function createStaff(bool $createWallet = true): array
    {
        $store = Store::create([
            'store_code' => 'test-store',
            'store_pin' => 'test-pin',
            'name' => 'Test Store',
        ]);

        $staff = Staff::create([
            'store_id' => $store->id,
            'staff_id' => 'test-staff',
            'name' => 'Test Staff',
            'pin' => 'test-pin',
            'role' => 'staff',
        ]);

        if ($createWallet) {
            Wallet::create([
                'store_id' => $store->id,
                'address' => self::RECIPIENT,
                'network' => 'kairos',
            ]);
        }

        return $createWallet
            ? [$store, $staff, $store->wallet()->firstOrFail()]
            : [$store, $staff];
    }
}
