<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Staff;
use App\Models\Store;
use App\Models\User;
use App\Models\Wallet;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentConfirmTest extends TestCase
{
    use RefreshDatabase;

    private const RPC_URL = 'https://rpc.example.test';

    private const TOKEN_ADDRESS = '0x1111111111111111111111111111111111111111';

    private const STORE_WALLET = '0x2222222222222222222222222222222222222222';

    private const SENDER_ADDRESS = '0x3333333333333333333333333333333333333333';

    private const OTHER_TOKEN_ADDRESS = '0x4444444444444444444444444444444444444444';

    private const OTHER_RECIPIENT = '0x5555555555555555555555555555555555555555';

    private const TX_HASH = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.web3.rpc_url' => self::RPC_URL,
            'services.web3.erc20_contract_address' => self::TOKEN_ADDRESS,
            'services.web3.chain_id' => 1001,
            'services.web3.token_decimals' => 18,
        ]);

        Http::preventStrayRequests();

        // The production status-alignment migration targets MySQL only. Tests
        // use SQLite, whose original enum check still lists legacy statuses.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = ON');
        }
    }

    public function test_valid_jpyc_transfer_confirms_a_pending_payment(): void
    {
        $payment = $this->createPayment();
        $user = $this->authenticateUser();
        $this->fakeRpc($this->successfulReceipt($payment->amount));

        $response = $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => $this->uppercaseTransactionHash()]
        );

        $response
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $payment->refresh();

        $this->assertSame('confirmed', $payment->status);
        $this->assertSame(self::TX_HASH, $payment->tx_hash);
        $this->assertSame($user->id, $payment->user_id);
        $this->assertNotNull($payment->paid_at);

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::RPC_URL
                && $request['method'] === 'eth_chainId'
                && $request['params'] === [];
        });
        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::RPC_URL
                && $request['method'] === 'eth_getTransactionReceipt'
                && $request['params'] === [self::TX_HASH];
        });
    }

    public function test_unauthenticated_confirmation_fails(): void
    {
        $payment = $this->createPayment();

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )->assertUnauthorized();

        $this->assertPaymentIsStillPending($payment);
        Http::assertNothingSent();
    }

    public function test_missing_payment_fails_before_rpc_access(): void
    {
        $this->authenticateUser();

        $this->postJson(
            '/api/payments/999999/confirm',
            ['tx_hash' => self::TX_HASH]
        )
            ->assertNotFound()
            ->assertExactJson(['error' => 'Payment not found']);

        Http::assertNothingSent();
    }

    public function test_malformed_tx_hash_fails(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => '0x1234']
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tx_hash');

        $this->assertPaymentIsStillPending($payment);
        Http::assertNothingSent();
    }

    public function test_non_hexadecimal_tx_hash_fails_before_rpc_access(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => '0x'.str_repeat('g', 64)]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tx_hash');

        $this->assertPaymentIsStillPending($payment);
        Http::assertNothingSent();
    }

    public function test_uppercase_tx_hash_prefix_is_rejected_before_rpc_access(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => strtoupper(self::TX_HASH)]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tx_hash');

        $this->assertPaymentIsStillPending($payment);
        Http::assertNothingSent();
    }

    public function test_rpc_chain_id_mismatch_fails_before_receipt_lookup(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $this->fakeRpc(
            $this->successfulReceipt($payment->amount),
            chainId: '0x1'
        );

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertInternalServerError()
            ->assertExactJson(['error' => 'WEB3 RPC chain mismatch']);

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request['method'] === 'eth_getTransactionReceipt');
    }

    public function test_malformed_chain_id_rpc_response_fails_safely(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $this->fakeRpc(
            $this->successfulReceipt($payment->amount),
            chainResponse: [
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => 'not-a-chain-id',
            ]
        );

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertInternalServerError()
            ->assertExactJson(['error' => 'Invalid WEB3 chain ID response']);

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(1);
    }

    public function test_missing_receipt_fails(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $this->fakeRpc(null);

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Transaction not found']);

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(11);
    }

    public function test_reverted_receipt_fails(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $this->fakeRpc([
            'status' => '0x0',
            'logs' => [],
        ]);

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Transaction failed']);

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(2);
    }

    public function test_malformed_receipt_structure_fails_safely(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $this->fakeRpc([
            'status' => '0x1',
            'logs' => 'not-an-array',
        ]);

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Invalid transaction']);

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(2);
    }

    public function test_wrong_token_contract_fails(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $this->fakeRpc($this->successfulReceipt(
            $payment->amount,
            contractAddress: self::OTHER_TOKEN_ADDRESS
        ));

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Invalid transaction']);

        $this->assertPaymentIsStillPending($payment);
    }

    public function test_wrong_recipient_fails(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $this->fakeRpc($this->successfulReceipt(
            $payment->amount,
            recipient: self::OTHER_RECIPIENT
        ));

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Invalid transaction']);

        $this->assertPaymentIsStillPending($payment);
    }

    public function test_wrong_amount_fails(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $this->fakeRpc($this->successfulReceipt($payment->amount + 1));

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => $this->uppercaseTransactionHash()]
        )
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Invalid transaction']);

        $this->assertPaymentIsStillPending($payment);
    }

    public function test_unrelated_logs_before_valid_transfer_are_ignored(): void
    {
        $payment = $this->createPayment();
        $user = $this->authenticateUser();
        $receipt = $this->successfulReceipt($payment->amount);
        array_unshift($receipt['logs'], [
            'address' => self::OTHER_TOKEN_ADDRESS,
            'topics' => ['0x1234'],
            'data' => '0x00',
        ]);
        $this->fakeRpc($receipt);

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $payment->refresh();
        $this->assertSame('confirmed', $payment->status);
        $this->assertSame($user->id, $payment->user_id);
    }

    public function test_configured_token_decimals_are_used(): void
    {
        config(['services.web3.token_decimals' => 6]);

        $payment = $this->createPayment();
        $this->authenticateUser();
        $this->fakeRpc($this->successfulReceipt($payment->amount));

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertSame('confirmed', $payment->refresh()->status);
    }

    public function test_expired_payment_fails(): void
    {
        $payment = $this->createPayment([
            'expires_at' => now()->subMinute(),
        ]);
        $this->authenticateUser();

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Expired']);

        $this->assertPaymentIsStillPending($payment);
        Http::assertNothingSent();
    }

    public function test_already_confirmed_payment_fails(): void
    {
        $payment = $this->createPayment([
            'status' => 'confirmed',
            'tx_hash' => self::TX_HASH,
            'paid_at' => now(),
        ]);
        $this->authenticateUser();

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Already paid']);

        $payment->refresh();
        $this->assertSame('confirmed', $payment->status);
        $this->assertSame(self::TX_HASH, $payment->tx_hash);
        Http::assertNothingSent();
    }

    public function test_non_pending_payment_fails_before_rpc_access(): void
    {
        $payment = $this->createPayment(['status' => 'failed']);
        $this->authenticateUser();

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Payment not pending']);

        $payment->refresh();
        $this->assertSame('failed', $payment->status);
        $this->assertNull($payment->tx_hash);
        Http::assertNothingSent();
    }

    public function test_duplicate_transaction_hash_fails(): void
    {
        $payment = $this->createPayment();
        Payment::create([
            'store_id' => $payment->store_id,
            'staff_id' => $payment->staff_id,
            'amount' => $payment->amount,
            'status' => 'confirmed',
            'tx_hash' => self::TX_HASH,
            'paid_at' => now(),
            'expires_at' => now()->addMinutes(10),
        ]);
        $this->authenticateUser();

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => $this->uppercaseTransactionHash()]
        )
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Duplicate tx_hash']);

        $this->assertPaymentIsStillPending($payment);
        Http::assertNothingSent();
    }

    public function test_finalization_rechecks_payment_state_inside_transaction(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $this->fakeRpc(
            $this->successfulReceipt($payment->amount),
            onRequest: function (string $method) use ($payment): void {
                if ($method === 'eth_getTransactionReceipt') {
                    DB::table('payments')
                        ->where('id', $payment->id)
                        ->update(['status' => 'confirmed']);
                }
            }
        );

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Already paid']);

        $payment->refresh();
        $this->assertSame('confirmed', $payment->status);
        $this->assertNull($payment->tx_hash);
        $this->assertNull($payment->paid_at);
    }

    public function test_rpc_requests_run_outside_database_transaction(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $baselineTransactionLevel = DB::transactionLevel();
        $transactionLevels = [];
        $this->fakeRpc(
            $this->successfulReceipt($payment->amount),
            onRequest: function () use (&$transactionLevels): void {
                $transactionLevels[] = DB::transactionLevel();
            }
        );

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )->assertOk();

        $this->assertSame(
            [$baselineTransactionLevel, $baselineTransactionLevel],
            $transactionLevels
        );
    }

    private function createPayment(array $overrides = []): Payment
    {
        $store = Store::create([
            'store_code' => 'confirm-test-store',
            'store_pin' => 'test-store-pin',
            'name' => 'Confirmation Test Store',
        ]);

        $staff = Staff::create([
            'store_id' => $store->id,
            'staff_id' => 'confirm-test-staff',
            'name' => 'Confirmation Test Staff',
            'pin' => 'test-staff-pin',
            'role' => 'staff',
        ]);

        Wallet::create([
            'store_id' => $store->id,
            'address' => self::STORE_WALLET,
            'network' => 'kairos',
        ]);

        return Payment::create(array_merge([
            'store_id' => $store->id,
            'staff_id' => $staff->id,
            'amount' => 125,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ], $overrides));
    }

    private function authenticateUser(): User
    {
        $user = User::create([
            'name' => 'Confirmation Test User',
            'email' => 'confirmation-test@example.test',
            'password' => 'not-used-by-this-test',
        ]);

        Sanctum::actingAs($user);

        return $user;
    }

    private function fakeRpc(
        mixed $receipt,
        mixed $chainId = '0x3e9',
        ?Closure $onRequest = null,
        ?array $chainResponse = null
    ): void {
        Http::fake(function (Request $request) use (
            $receipt,
            $chainId,
            $onRequest,
            $chainResponse
        ) {
            $method = $request['method'];
            $onRequest?->__invoke($method);

            if ($method === 'eth_chainId') {
                return Http::response($chainResponse ?? [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => $chainId,
                ]);
            }

            if ($method === 'eth_getTransactionReceipt') {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => $receipt,
                ]);
            }

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => ['message' => 'Unexpected RPC method'],
            ], 500);
        });
    }

    private function successfulReceipt(
        int $amount,
        string $contractAddress = self::TOKEN_ADDRESS,
        string $recipient = self::STORE_WALLET
    ): array {
        $atomicAmount = bcmul(
            (string) $amount,
            bcpow('10', (string) config('services.web3.token_decimals'), 0),
            0
        );
        $amountHex = str_pad(
            gmp_strval(gmp_init($atomicAmount, 10), 16),
            64,
            '0',
            STR_PAD_LEFT
        );

        return [
            'status' => '0x1',
            'logs' => [
                [
                    'address' => $contractAddress,
                    'topics' => [
                        self::TRANSFER_TOPIC,
                        $this->addressTopic(self::SENDER_ADDRESS),
                        $this->addressTopic($recipient),
                    ],
                    'data' => '0x'.$amountHex,
                ],
            ],
        ];
    }

    private function addressTopic(string $address): string
    {
        return '0x'.str_pad(
            substr(strtolower($address), 2),
            64,
            '0',
            STR_PAD_LEFT
        );
    }

    private function uppercaseTransactionHash(): string
    {
        return '0x'.strtoupper(substr(self::TX_HASH, 2));
    }

    private function assertPaymentIsStillPending(Payment $payment): void
    {
        $payment->refresh();

        $this->assertSame('pending', $payment->status);
        $this->assertNull($payment->tx_hash);
        $this->assertNull($payment->paid_at);
        $this->assertNull($payment->user_id);
    }
}
