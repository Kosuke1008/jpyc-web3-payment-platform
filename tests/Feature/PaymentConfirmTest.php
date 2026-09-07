<?php

namespace Tests\Feature;

use App\Blockchain\NetworkProfileRegistry;
use App\Models\Payment;
use App\Models\Staff;
use App\Models\Store;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\PaymentSnapshot;
use Closure;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use LogicException;
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

    private const BLOCK_HASH = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'blockchain.network' => 'kairos',
            'blockchain.profiles.kairos.rpc_url' => self::RPC_URL,
            'blockchain.profiles.kairos.jpyc.contract' => self::TOKEN_ADDRESS,
            'blockchain.profiles.kairos.chain_id' => 1001,
            'blockchain.profiles.kairos.jpyc.decimals' => 18,
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
        $this->assertSame(1001, $payment->observed_chain_id);
        $this->assertSame(16, $payment->confirmed_block_number);
        $this->assertSame(self::BLOCK_HASH, $payment->confirmed_block_hash);
        $this->assertSame(1, $payment->receipt_status);
        $this->assertSame(self::SENDER_ADDRESS, $payment->payer_address);
        $this->assertSame(0, $payment->transfer_log_index);
        $this->assertNotNull($payment->chain_confirmed_at);
        $this->assertNotNull($payment->verified_at);
        $this->assertSame('pending', $payment->reconciliation_status);

        Http::assertSentCount(4);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::RPC_URL
                && $request['method'] === 'eth_chainId'
                && $request['params'] === [];
        });
        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::RPC_URL
                && $request['method'] === 'eth_getTransactionByHash'
                && $request['params'] === [self::TX_HASH];
        });
        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::RPC_URL
                && $request['method'] === 'eth_getTransactionReceipt'
                && $request['params'] === [self::TX_HASH];
        });
        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::RPC_URL
                && $request['method'] === 'eth_getBlockByNumber'
                && $request['params'] === ['0x10', false];
        });
    }

    public function test_valid_third_party_payer_is_accepted_and_stored_separately(): void
    {
        $payment = $this->createPayment();
        $user = $this->authenticateUser();
        $user->forceFill(['wallet_address' => self::OTHER_RECIPIENT])->save();
        $this->fakeRpc($this->successfulReceipt($payment->amount));

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )->assertOk();

        $payment->refresh();

        $this->assertSame($user->id, $payment->user_id);
        $this->assertSame(self::SENDER_ADDRESS, $payment->payer_address);
        $this->assertNotSame($user->wallet_address, $payment->payer_address);
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

    public function test_rpc_connection_failure_fails_safely(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        Http::fake(Http::failedConnection('Connection failed at private RPC URL'));

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertServiceUnavailable()
            ->assertExactJson(['error' => 'WEB3 RPC is unavailable']);

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(1);
    }

    public function test_rpc_timeout_fails_safely(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        Http::fake(Http::failedConnection('Operation timed out with provider credentials'));

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertServiceUnavailable()
            ->assertExactJson(['error' => 'WEB3 RPC is unavailable']);

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(1);
    }

    public function test_transaction_rpc_timeout_fails_after_chain_check(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $failedConnection = Http::failedConnection('Transaction lookup timed out');

        Http::fake(fn (Request $request) => $request['method'] === 'eth_chainId'
            ? Http::response([
                'jsonrpc' => '2.0', 'id' => 1, 'result' => '0x3e9',
            ])
            : $failedConnection($request));

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )->assertServiceUnavailable();

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(2);
    }

    public function test_rpc_http_4xx_response_fails_safely(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        Http::fake(fn () => Http::response(
            'Provider rejected credential secret',
            429
        ));

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertServiceUnavailable()
            ->assertExactJson(['error' => 'WEB3 RPC is unavailable']);

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(1);
    }

    public function test_rpc_http_5xx_response_fails_safely(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        Http::fake(fn () => Http::response(
            'Provider failed at internal RPC URL',
            502
        ));

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertServiceUnavailable()
            ->assertExactJson(['error' => 'WEB3 RPC is unavailable']);

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(1);
    }

    public function test_empty_rpc_response_body_fails_safely(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        Http::fake(fn () => Http::response(''));

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertInternalServerError()
            ->assertExactJson(['error' => 'Invalid WEB3 RPC response']);

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(1);
    }

    public function test_non_json_rpc_response_body_fails_safely(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        Http::fake(fn () => Http::response(
            '<html>Private upstream error</html>',
            headers: ['Content-Type' => 'text/html']
        ));

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertInternalServerError()
            ->assertExactJson(['error' => 'Invalid WEB3 RPC response']);

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(1);
    }

    public function test_invalid_json_rpc_response_fails_safely(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        Http::fake(fn () => Http::response(
            '{"jsonrpc":"2.0","id":1,"result":',
            headers: ['Content-Type' => 'application/json']
        ));

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertInternalServerError()
            ->assertExactJson(['error' => 'Invalid WEB3 RPC response']);

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(1);
    }

    public function test_rpc_response_missing_result_fails_safely(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        Http::fake(fn () => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
        ]));

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertInternalServerError()
            ->assertExactJson(['error' => 'Invalid WEB3 RPC response']);

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(1);
    }

    public function test_json_rpc_provider_error_fails_without_retrying(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        Http::fake(fn () => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => [
                'code' => -32601,
                'message' => 'Private provider message with RPC URL',
            ],
        ]));

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertStatus(502)
            ->assertExactJson(['error' => 'WEB3 RPC provider error']);

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(1);
    }

    public function test_malformed_transaction_receipt_result_fails_safely(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $this->fakeRpc('not-a-receipt');

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Invalid transaction']);

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(3);
    }

    public function test_null_transaction_fails_before_receipt_lookup(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $this->fakeRpc(
            $this->successfulReceipt($payment->amount),
            transaction: null
        );

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Transaction not found']);

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(2);
    }

    public function test_transaction_hash_mismatch_fails_closed(): void
    {
        $payment = $this->createPayment();
        $transaction = $this->successfulTransaction();
        $transaction['hash'] = '0x'.str_repeat('c', 64);
        $this->authenticateUser();
        $this->fakeRpc(
            $this->successfulReceipt($payment->amount),
            transaction: $transaction
        );

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )->assertBadRequest();

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(2);
    }

    public function test_receipt_hash_mismatch_fails_closed(): void
    {
        $payment = $this->createPayment();
        $receipt = $this->successfulReceipt($payment->amount);
        $receipt['transactionHash'] = '0x'.str_repeat('c', 64);
        $this->authenticateUser();
        $this->fakeRpc($receipt);

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )->assertBadRequest();

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(3);
    }

    public function test_transaction_and_receipt_block_hash_mismatch_fails_closed(): void
    {
        $payment = $this->createPayment();
        $transaction = $this->successfulTransaction();
        $transaction['blockHash'] = '0x'.str_repeat('c', 64);
        $this->authenticateUser();
        $this->fakeRpc(
            $this->successfulReceipt($payment->amount),
            transaction: $transaction
        );

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )->assertBadRequest();

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(3);
    }

    public function test_transaction_and_receipt_block_number_mismatch_fails_closed(): void
    {
        $payment = $this->createPayment();
        $transaction = $this->successfulTransaction();
        $transaction['blockNumber'] = '0x11';
        $this->authenticateUser();
        $this->fakeRpc(
            $this->successfulReceipt($payment->amount),
            transaction: $transaction
        );

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )->assertBadRequest();

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(3);
    }

    public function test_missing_block_reference_fails_closed(): void
    {
        $payment = $this->createPayment();
        $receipt = $this->successfulReceipt($payment->amount);
        unset($receipt['blockHash']);
        $this->authenticateUser();
        $this->fakeRpc($receipt);

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )->assertBadRequest();

        $this->assertPaymentIsStillPending($payment);
    }

    public function test_missing_block_number_fails_closed(): void
    {
        $payment = $this->createPayment();
        $receipt = $this->successfulReceipt($payment->amount);
        unset($receipt['blockNumber']);
        $this->authenticateUser();
        $this->fakeRpc($receipt);

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )->assertBadRequest();

        $this->assertPaymentIsStillPending($payment);
    }

    public function test_canonical_block_mismatch_fails_closed(): void
    {
        $payment = $this->createPayment();
        $block = $this->canonicalBlock();
        $block['hash'] = '0x'.str_repeat('c', 64);
        $this->authenticateUser();
        $this->fakeRpc(
            $this->successfulReceipt($payment->amount),
            block: $block
        );

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )->assertBadRequest();

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(4);
    }

    public function test_canonical_block_must_contain_requested_transaction(): void
    {
        $payment = $this->createPayment();
        $block = $this->canonicalBlock();
        $block['transactions'] = ['0x'.str_repeat('c', 64)];
        $this->authenticateUser();
        $this->fakeRpc(
            $this->successfulReceipt($payment->amount),
            block: $block
        );

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )->assertBadRequest();

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(4);
    }

    public function test_removed_transfer_log_fails_closed(): void
    {
        $payment = $this->createPayment();
        $receipt = $this->successfulReceipt($payment->amount);
        $receipt['logs'][0]['removed'] = true;
        $this->authenticateUser();
        $this->fakeRpc($receipt);

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )->assertBadRequest();

        $this->assertPaymentIsStillPending($payment);
    }

    public function test_malformed_transfer_log_fails_closed(): void
    {
        $payment = $this->createPayment();
        $receipt = $this->successfulReceipt($payment->amount);
        $receipt['logs'][0]['topics'][1] = '0x1234';
        $this->authenticateUser();
        $this->fakeRpc($receipt);

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )->assertBadRequest();

        $this->assertPaymentIsStillPending($payment);
    }

    public function test_zero_address_transfer_sender_is_rejected(): void
    {
        $payment = $this->createPayment();
        $receipt = $this->successfulReceipt($payment->amount);
        $receipt['logs'][0]['topics'][1] = $this->addressTopic(
            '0x0000000000000000000000000000000000000000'
        );
        $this->authenticateUser();
        $this->fakeRpc($receipt);

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )->assertBadRequest();

        $this->assertPaymentIsStillPending($payment);
    }

    public function test_transfer_sender_must_match_transaction_sender(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $transaction = $this->successfulTransaction();
        $transaction['from'] = self::OTHER_RECIPIENT;
        $this->fakeRpc(
            $this->successfulReceipt($payment->amount),
            transaction: $transaction
        );

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Invalid transaction']);

        $this->assertPaymentIsStillPending($payment);
    }

    public function test_receipt_rpc_failure_after_successful_chain_id_fails_without_finalization_transaction(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $baselineTransactionLevel = DB::transactionLevel();
        $transactionLevels = [];
        $transactionsStarted = 0;
        Event::listen(
            TransactionBeginning::class,
            function () use (&$transactionsStarted): void {
                $transactionsStarted++;
            }
        );
        $failedConnection = Http::failedConnection(
            'Receipt timeout at private RPC URL'
        );

        Http::fake(function (Request $request) use (
            $baselineTransactionLevel,
            &$transactionLevels,
            $failedConnection
        ) {
            $transactionLevels[] = DB::transactionLevel();

            if ($request['method'] === 'eth_chainId') {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => '0x3e9',
                ]);
            }

            if ($request['method'] === 'eth_getTransactionByHash') {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => $this->successfulTransaction(),
                ]);
            }

            $this->assertSame(
                $baselineTransactionLevel,
                DB::transactionLevel()
            );

            return $failedConnection($request);
        });

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertServiceUnavailable()
            ->assertExactJson(['error' => 'WEB3 RPC is unavailable']);

        $this->assertPaymentIsStillPending($payment);
        $this->assertSame(
            [
                $baselineTransactionLevel,
                $baselineTransactionLevel,
                $baselineTransactionLevel,
            ],
            $transactionLevels
        );
        $this->assertSame(0, $transactionsStarted);
        Http::assertSentCount(3);
    }

    public function test_block_rpc_timeout_does_not_confirm_payment(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $failedConnection = Http::failedConnection('Block lookup timed out');

        Http::fake(function (Request $request) use ($payment, $failedConnection) {
            return match ($request['method']) {
                'eth_chainId' => Http::response([
                    'jsonrpc' => '2.0', 'id' => 1, 'result' => '0x3e9',
                ]),
                'eth_getTransactionByHash' => Http::response([
                    'jsonrpc' => '2.0', 'id' => 1,
                    'result' => $this->successfulTransaction(),
                ]),
                'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0', 'id' => 1,
                    'result' => $this->successfulReceipt($payment->amount),
                ]),
                default => $failedConnection($request),
            };
        });

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertServiceUnavailable()
            ->assertExactJson(['error' => 'WEB3 RPC is unavailable']);

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(4);
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
        Http::assertSentCount(12);
    }

    public function test_reverted_receipt_fails(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $receipt = $this->successfulReceipt($payment->amount);
        $receipt['status'] = '0x0';
        $receipt['logs'] = [];
        $this->fakeRpc($receipt);

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Transaction failed']);

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(3);
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
        Http::assertSentCount(3);
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
        config(['blockchain.profiles.kairos.jpyc.decimals' => 6]);

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

    public function test_verification_uses_snapshot_after_current_token_configuration_changes(): void
    {
        $payment = $this->createPayment();
        $receipt = $this->successfulReceipt($payment->amount);
        config([
            'blockchain.profiles.kairos.jpyc.contract' => self::OTHER_TOKEN_ADDRESS,
            'blockchain.profiles.kairos.jpyc.decimals' => 6,
        ]);
        $this->authenticateUser();
        $this->fakeRpc($receipt);

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )->assertOk();

        $this->assertSame('confirmed', $payment->refresh()->status);
    }

    public function test_transaction_matching_current_config_but_not_snapshot_is_rejected(): void
    {
        $payment = $this->createPayment();
        config([
            'blockchain.profiles.kairos.jpyc.contract' => self::OTHER_TOKEN_ADDRESS,
            'blockchain.profiles.kairos.jpyc.decimals' => 6,
        ]);
        $this->authenticateUser();
        $this->fakeRpc($this->successfulReceipt(
            $payment->amount,
            self::OTHER_TOKEN_ADDRESS
        ));

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Invalid transaction']);

        $this->assertPaymentIsStillPending($payment);
    }

    public function test_kairos_snapshot_is_not_reinterpreted_when_active_network_changes(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $this->fakeRpc($this->successfulReceipt($payment->amount));
        config(['blockchain.network' => 'kaia-mainnet']);

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )->assertOk();

        $this->assertSame('confirmed', $payment->refresh()->status);
    }

    public function test_legacy_payment_without_snapshot_fails_closed_before_rpc(): void
    {
        $payment = $this->createPayment();
        DB::table('payments')->where('id', $payment->id)->update(
            $this->emptySnapshotAttributes()
        );
        $this->authenticateUser();

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertStatus(409)
            ->assertExactJson(['error' => 'Payment snapshot unavailable']);

        Http::assertNothingSent();
        $this->assertPaymentIsStillPending($payment);
    }

    public function test_explicitly_backfilled_legacy_payment_verifies_normally(): void
    {
        $payment = $this->createPayment();
        $snapshot = PaymentSnapshot::fromRecord($payment);
        DB::table('payments')->where('id', $payment->id)->update(
            $this->emptySnapshotAttributes()
        );
        DB::table('payments')->where('id', $payment->id)->update(
            $snapshot->databaseAttributes()
        );
        $this->authenticateUser();
        $this->fakeRpc($this->successfulReceipt($payment->amount));

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )->assertOk();

        $this->assertSame('confirmed', $payment->refresh()->status);
    }

    public function test_mainnet_snapshot_remains_blocked_before_rpc(): void
    {
        $payment = $this->createPayment();
        $mainnetSnapshot = PaymentSnapshot::create(
            app(NetworkProfileRegistry::class)->get('kaia-mainnet'),
            self::STORE_WALLET,
            $payment->amount,
            $payment->expires_at
        );
        DB::table('payments')->where('id', $payment->id)->update(
            $mainnetSnapshot->databaseAttributes()
        );
        $this->authenticateUser();

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertServiceUnavailable()
            ->assertExactJson([
                'error' => 'Payment execution is disabled for this network',
            ]);

        Http::assertNothingSent();
        $this->assertPaymentIsStillPending($payment);
    }

    public function test_expired_payment_fails(): void
    {
        $payment = $this->createPayment([
            'expires_at' => now()->subMinute(),
        ]);
        $this->authenticateUser();
        $this->fakeRpc($this->successfulReceipt($payment->amount));

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Expired']);

        $this->assertPaymentIsStillPending($payment);
        Http::assertSentCount(4);
    }

    public function test_transaction_mined_before_expiry_can_be_confirmed_after_api_delay(): void
    {
        $payment = $this->createPayment([
            'expires_at' => now()->addMinute(),
        ]);
        $block = $this->canonicalBlock();
        $block['timestamp'] = '0x'.dechex(now()->addSeconds(30)->timestamp);
        $this->travel(2)->minutes();
        $this->authenticateUser();
        $this->fakeRpc(
            $this->successfulReceipt($payment->amount),
            block: $block
        );

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )->assertOk();

        $this->assertSame('confirmed', $payment->fresh()->status);
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

    public function test_repeating_a_successful_confirmation_is_idempotent(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $this->fakeRpc($this->successfulReceipt($payment->amount));

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )->assertOk();

        $originalEvidence = $payment->fresh()->only([
            'tx_hash',
            'confirmed_block_number',
            'confirmed_block_hash',
            'payer_address',
            'transfer_log_index',
        ]);
        $originalChainConfirmedAt = $payment->fresh()->chain_confirmed_at;
        $originalVerifiedAt = $payment->fresh()->verified_at;

        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )->assertOk();

        $this->assertSame($originalEvidence, $payment->fresh()->only(
            array_keys($originalEvidence)
        ));
        $this->assertTrue(
            $originalChainConfirmedAt->equalTo($payment->fresh()->chain_confirmed_at)
        );
        $this->assertTrue(
            $originalVerifiedAt->equalTo($payment->fresh()->verified_at)
        );
        Http::assertSentCount(4);
    }

    public function test_confirmed_evidence_cannot_be_replaced_through_model_update(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $this->fakeRpc($this->successfulReceipt($payment->amount));
        $this->postJson(
            "/api/payments/{$payment->id}/confirm",
            ['tx_hash' => self::TX_HASH]
        )->assertOk();

        $payment->refresh();
        $payment->confirmed_block_hash = '0x'.str_repeat('c', 64);

        $this->expectException(LogicException::class);
        $payment->save();
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
        $duplicate = PaymentSnapshot::create(
            app(NetworkProfileRegistry::class)->get('kairos'),
            self::STORE_WALLET,
            $payment->amount,
            now()->addMinutes(10)
        );
        Payment::create(array_merge([
            'store_id' => $payment->store_id,
            'staff_id' => $payment->staff_id,
            'amount' => $payment->amount,
            'status' => 'confirmed',
            'tx_hash' => self::TX_HASH,
            'paid_at' => now(),
        ], $duplicate->databaseAttributes()));
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
            [
                $baselineTransactionLevel,
                $baselineTransactionLevel,
                $baselineTransactionLevel,
                $baselineTransactionLevel,
            ],
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

        $attributes = array_merge([
            'store_id' => $store->id,
            'staff_id' => $staff->id,
            'amount' => 125,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ], $overrides);
        $snapshot = PaymentSnapshot::create(
            app(NetworkProfileRegistry::class)->get('kairos'),
            self::STORE_WALLET,
            $attributes['amount'],
            $attributes['expires_at']
        );

        return Payment::create(array_merge(
            $attributes,
            $snapshot->databaseAttributes(),
            $overrides
        ));
    }

    private function authenticateUser(): User
    {
        $user = User::create([
            'name' => 'Confirmation Test User',
            'email' => 'confirmation-test@example.test',
            'password' => 'not-used-by-this-test',
        ]);

        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /** @return array<string, null> */
    private function emptySnapshotAttributes(): array
    {
        return [
            'network' => null,
            'network_profile_version' => null,
            'chain_id' => null,
            'token_contract' => null,
            'token_symbol' => null,
            'token_decimals' => null,
            'recipient_address' => null,
            'display_amount' => null,
            'atomic_amount' => null,
        ];
    }

    private function fakeRpc(
        mixed $receipt,
        mixed $chainId = '0x3e9',
        ?Closure $onRequest = null,
        ?array $chainResponse = null,
        mixed $transaction = '__default__',
        mixed $block = '__default__'
    ): void {
        if ($transaction === '__default__') {
            $transaction = $this->successfulTransaction();
        }

        if ($block === '__default__') {
            $block = $this->canonicalBlock();
        }

        Http::fake(function (Request $request) use (
            $receipt,
            $chainId,
            $onRequest,
            $chainResponse,
            $transaction,
            $block
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

            if ($method === 'eth_getTransactionByHash') {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => $transaction,
                ]);
            }

            if ($method === 'eth_getTransactionReceipt') {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => $receipt,
                ]);
            }

            if ($method === 'eth_getBlockByNumber') {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => $block,
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
            bcpow('10', (string) config('blockchain.profiles.kairos.jpyc.decimals'), 0),
            0
        );
        $amountHex = str_pad(
            gmp_strval(gmp_init($atomicAmount, 10), 16),
            64,
            '0',
            STR_PAD_LEFT
        );

        return [
            'transactionHash' => self::TX_HASH,
            'blockNumber' => '0x10',
            'blockHash' => self::BLOCK_HASH,
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
                    'logIndex' => '0x0',
                    'removed' => false,
                ],
            ],
        ];
    }

    private function successfulTransaction(): array
    {
        return [
            'hash' => self::TX_HASH,
            'blockNumber' => '0x10',
            'blockHash' => self::BLOCK_HASH,
            'from' => self::SENDER_ADDRESS,
        ];
    }

    private function canonicalBlock(): array
    {
        return [
            'number' => '0x10',
            'hash' => self::BLOCK_HASH,
            'timestamp' => '0x'.dechex(now()->timestamp),
            'transactions' => [self::TX_HASH],
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
