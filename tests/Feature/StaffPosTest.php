<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Staff;
use App\Models\Store;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffPosTest extends TestCase
{
    use RefreshDatabase;

    private const RECIPIENT = '0x2222222222222222222222222222222222222222';

    private const TOKEN = '0xe7c3d8c9a439fede00d2600032d5db0be71c3c29';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'blockchain.network' => 'kairos',
            'blockchain.payment_max_jpy' => 100_000,
            'blockchain.payment_expiration_seconds' => 600,
            'blockchain.profiles.kairos.chain_id' => 1001,
            'blockchain.profiles.kairos.jpyc.contract' => self::TOKEN,
            'blockchain.profiles.kairos.jpyc.symbol' => 'JPYC',
            'blockchain.profiles.kairos.jpyc.decimals' => 18,
            'pos.products' => [
                ['code' => 'coffee', 'name' => 'コーヒー', 'amount' => 1],
                ['code' => 'too-expensive', 'name' => '対象外', 'amount' => 100_001],
            ],
            'pos.history_limit' => 20,
        ]);
    }

    public function test_staff_authentication_is_required_for_pos_apis(): void
    {
        $this->getJson('/api/staff/pos/context')->assertUnauthorized();
        $this->getJson('/api/staff/payments')->assertUnauthorized();
    }

    public function test_context_returns_active_network_store_wallet_and_valid_products(): void
    {
        [$store, $staff] = $this->createStoreStaffAndWallet();
        Sanctum::actingAs($staff, ['payment:create']);

        $this->getJson('/api/staff/pos/context')
            ->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('creation_available', true)
            ->assertJsonPath('store.id', $store->id)
            ->assertJsonPath('staff.id', $staff->id)
            ->assertJsonPath('wallet.address', self::RECIPIENT)
            ->assertJsonPath('network.id', 'kairos')
            ->assertJsonPath('maximum_amount', 100_000)
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.name', 'コーヒー')
            ->assertJsonPath('products.0.amount', 1);
    }

    public function test_context_fails_closed_for_a_wallet_on_another_network(): void
    {
        [, $staff, $wallet] = $this->createStoreStaffAndWallet();
        $wallet->network = 'kaia-mainnet';
        $wallet->save();
        Sanctum::actingAs($staff, ['payment:create']);

        $this->getJson('/api/staff/pos/context')
            ->assertOk()
            ->assertJsonPath('ready', false)
            ->assertJsonPath('creation_available', false)
            ->assertJsonPath('wallet', null)
            ->assertJsonPath('diagnostic', 'store_wallet_or_network_mismatch');
    }

    public function test_history_is_scoped_to_authenticated_store_and_active_network(): void
    {
        [$store, $staff] = $this->createStoreStaffAndWallet();
        [$otherStore, $otherStaff] = $this->createStoreStaffAndWallet('other');

        $included = $this->createPayment($store, $staff, 'kairos', now()->addMinutes(10));
        $expired = $this->createPayment($store, $staff, 'kairos', now()->subSecond());
        $this->createPayment($store, $staff, 'kaia-mainnet', now()->addMinutes(10));
        $this->createPayment($otherStore, $otherStaff, 'kairos', now()->addMinutes(10));

        Sanctum::actingAs($staff, ['payment:create']);
        $response = $this->getJson('/api/staff/payments')
            ->assertOk()
            ->assertJsonPath('network', 'kairos')
            ->assertJsonCount(2, 'payments');

        $this->assertSame(
            [$expired->id, $included->id],
            array_column($response->json('payments'), 'id')
        );
        $this->assertSame('expired', $response->json('payments.0.status'));
        $this->assertSame('pending', $response->json('payments.1.status'));
    }

    public function test_public_status_reports_expiration_without_mutating_payment(): void
    {
        [$store, $staff] = $this->createStoreStaffAndWallet();
        $payment = $this->createPayment($store, $staff, 'kairos', now()->subSecond());

        $this->getJson('/api/payments/status/'.$payment->id)
            ->assertOk()
            ->assertJsonPath('status', 'expired');

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_store_pages_load_external_scripts_and_pos_avoids_html_injection(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('staff-login-form')
            ->assertSee('/js/staff-login.js');
        $this->get('/pos')
            ->assertOk()
            ->assertSee('product-buttons')
            ->assertSee('history-body')
            ->assertSee('/js/pos.js');

        $script = file_get_contents(public_path('js/pos.js'));
        $this->assertIsString($script);
        $this->assertStringNotContainsString('innerHTML', $script);
        $this->assertStringContainsString('qr_code_base64', $script);
    }

    /** @return array{Store, Staff, Wallet} */
    private function createStoreStaffAndWallet(string $suffix = 'test'): array
    {
        $store = Store::create([
            'store_code' => $suffix.'-store',
            'store_pin' => 'store-pin',
            'name' => ucfirst($suffix).' Store',
        ]);
        $staff = Staff::create([
            'store_id' => $store->id,
            'staff_id' => $suffix.'-staff',
            'name' => ucfirst($suffix).' Staff',
            'pin' => 'staff-pin',
            'role' => 'staff',
        ]);
        $wallet = Wallet::create([
            'store_id' => $store->id,
            'address' => self::RECIPIENT,
            'network' => 'kairos',
        ]);

        return [$store, $staff, $wallet];
    }

    private function createPayment(
        Store $store,
        Staff $staff,
        string $network,
        mixed $expiresAt
    ): Payment {
        $chainId = $network === 'kaia-mainnet' ? 8217 : 1001;

        return Payment::create([
            'store_id' => $store->id,
            'staff_id' => $staff->id,
            'amount' => 1,
            'status' => 'pending',
            'network' => $network,
            'network_profile_version' => 1,
            'chain_id' => $chainId,
            'token_contract' => self::TOKEN,
            'token_symbol' => 'JPYC',
            'token_decimals' => 18,
            'recipient_address' => self::RECIPIENT,
            'display_amount' => '1',
            'atomic_amount' => '1000000000000000000',
            'expires_at' => $expiresAt,
        ]);
    }
}
