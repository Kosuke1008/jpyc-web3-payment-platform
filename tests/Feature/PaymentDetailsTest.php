<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Staff;
use App\Models\Store;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PaymentDetailsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_ADDRESS = '0xe7c3d8c9a439fede00d2600032d5db0be71c3c29';

    private const RECIPIENT_ADDRESS = '0x2222222222222222222222222222222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.web3.network' => 'kairos',
            'services.web3.chain_name' => 'Kaia Kairos Testnet',
            'services.web3.chain_id' => 1001,
            'services.web3.erc20_contract_address' => self::TOKEN_ADDRESS,
            'services.web3.token_symbol' => 'JPYC',
            'services.web3.token_decimals' => 18,
            'services.livt_wallet.url' => 'https://wallet.example.test/',
        ]);
    }

    public function test_public_payment_details_are_authoritative_and_source_neutral(): void
    {
        Carbon::setTestNow('2026-07-18T12:00:00+09:00');
        $payment = $this->createPayment([
            'amount' => 125,
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->getJson("/api/payments/{$payment->id}");

        $response
            ->assertOk()
            ->assertExactJson([
                'id' => $payment->id,
                'amount' => 125,
                'display_amount' => '125',
                'atomic_amount' => '125000000000000000000',
                'status' => 'pending',
                'store_name' => 'Payment Details Store',
                'recipient_address' => self::RECIPIENT_ADDRESS,
                'network' => 'kairos',
                'chain_name' => 'Kaia Kairos Testnet',
                'chain_id' => 1001,
                'token_contract' => self::TOKEN_ADDRESS,
                'token_symbol' => 'JPYC',
                'token_decimals' => 18,
                'expires_at' => '2026-07-18 03:10:00',
                'expires_at_iso' => '2026-07-18T03:10:00+00:00',
            ]);

        $encodedResponse = $response->getContent();
        $this->assertStringNotContainsString('payment-details-pin', $encodedResponse);
        $this->assertStringNotContainsString('payment-details-staff', $encodedResponse);
        $this->assertStringNotContainsString('staff_id', $encodedResponse);
        $this->assertStringNotContainsString('user_id', $encodedResponse);
        $this->assertStringNotContainsString('rpc_url', $encodedResponse);
    }

    public function test_payment_details_preserve_confirmed_and_expired_state(): void
    {
        Carbon::setTestNow('2026-07-18T12:00:00+09:00');
        $confirmed = $this->createPayment([
            'status' => 'confirmed',
            'expires_at' => now()->addMinute(),
        ]);
        $expired = $this->createPayment([
            'expires_at' => now()->subMinute(),
        ], 'expired');

        $this->getJson("/api/payments/{$confirmed->id}")
            ->assertOk()
            ->assertJsonPath('status', 'confirmed');

        $this->getJson("/api/payments/{$expired->id}")
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('expires_at', '2026-07-18 02:59:00')
            ->assertJsonPath('expires_at_iso', '2026-07-18T02:59:00+00:00');
    }

    public function test_missing_store_wallet_fails_without_exposing_configuration(): void
    {
        $payment = $this->createPayment(createWallet: false);

        $this->getJson("/api/payments/{$payment->id}")
            ->assertInternalServerError()
            ->assertExactJson(['error' => 'Payment details unavailable']);
    }

    public function test_invalid_payment_configuration_fails_safely(): void
    {
        $payment = $this->createPayment();
        config(['services.web3.erc20_contract_address' => 'not-an-address']);

        $this->getJson("/api/payments/{$payment->id}")
            ->assertInternalServerError()
            ->assertExactJson(['error' => 'Payment details unavailable']);

        config([
            'services.web3.erc20_contract_address' => self::TOKEN_ADDRESS,
            'services.web3.token_decimals' => 'not-a-number',
        ]);

        $this->getJson("/api/payments/{$payment->id}")
            ->assertInternalServerError()
            ->assertExactJson(['error' => 'Payment details unavailable']);
    }

    public function test_missing_payment_returns_not_found(): void
    {
        $this->getJson('/api/payments/999999')->assertNotFound();
    }

    public function test_payment_page_keeps_metamask_and_adds_configured_wallet_option(): void
    {
        $payment = $this->createPayment();

        $this->get("/pay/{$payment->id}")
            ->assertOk()
            ->assertSee('id="connect-button"', false)
            ->assertSee('id="pay-button"', false)
            ->assertSee('window.ethereum', false)
            ->assertSee('/api/payments/${PAYMENT_ID}/confirm', false)
            ->assertSee('!userToken && !HAS_LIVT_WALLET_OPTION', false)
            ->assertSee('有効期限', false)
            ->assertSee('id="livt-wallet-button"', false)
            ->assertSee(
                "https://wallet.example.test/?payment_id={$payment->id}",
                false
            );
    }

    public function test_wallet_option_is_hidden_when_not_configured(): void
    {
        config(['services.livt_wallet.url' => null]);
        $payment = $this->createPayment([
            'expires_at' => null,
        ]);

        $this->get("/pay/{$payment->id}")
            ->assertOk()
            ->assertSee('id="connect-button"', false)
            ->assertDontSee('id="livt-wallet-button"', false);
    }

    public function test_wallet_option_rejects_urls_with_credentials_or_existing_payload(): void
    {
        $payment = $this->createPayment([
            'expires_at' => null,
        ]);

        foreach ([
            'https://user:secret@wallet.example.test/',
            'https://wallet.example.test/?token=secret',
            'https://wallet.example.test/#payment',
            'http://wallet.example.test/',
        ] as $unsafeUrl) {
            config(['services.livt_wallet.url' => $unsafeUrl]);

            $this->get("/pay/{$payment->id}")
                ->assertOk()
                ->assertDontSee('id="livt-wallet-button"', false);
        }
    }

    public function test_api_cors_preflight_allows_bearer_requests_without_credentials(): void
    {
        $response = $this->call(
            'OPTIONS',
            '/api/payments/1',
            server: [
                'HTTP_ORIGIN' => 'https://wallet.example.test',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Authorization, Content-Type',
            ]
        );

        $response
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    private function createPayment(
        array $overrides = [],
        string $suffix = 'default',
        bool $createWallet = true
    ): Payment {
        $store = Store::create([
            'store_code' => "payment-details-store-{$suffix}",
            'store_pin' => 'payment-details-pin',
            'name' => 'Payment Details Store',
        ]);

        $staff = Staff::create([
            'store_id' => $store->id,
            'staff_id' => "payment-details-staff-{$suffix}",
            'name' => 'Payment Details Staff',
            'pin' => 'payment-details-staff-pin',
            'role' => 'staff',
        ]);

        if ($createWallet) {
            Wallet::create([
                'store_id' => $store->id,
                'address' => self::RECIPIENT_ADDRESS,
                'network' => 'kairos',
            ]);
        }

        return Payment::create(array_merge([
            'store_id' => $store->id,
            'staff_id' => $staff->id,
            'amount' => 125,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ], $overrides));
    }
}
