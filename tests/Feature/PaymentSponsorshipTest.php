<?php

namespace Tests\Feature;

use App\Blockchain\NetworkProfileRegistry;
use App\Models\Payment;
use App\Models\PaymentFeeDelegationAttempt;
use App\Models\Staff;
use App\Models\Store;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\PaymentSnapshot;
use App\Services\Payments\FeeDelegationAttemptResolver;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentSponsorshipTest extends TestCase
{
    use RefreshDatabase;

    private array $resolverResults = [];

    private const RPC_URL = 'https://rpc.example.test';

    private const FEE_DELEGATION_URL = 'https://fee.example.test';

    private const MANAGED_FEE_DELEGATION_URL = 'https://fee-delegation-kairos.kaia.io';

    private const LOOPBACK_FEE_PAYER_URL = 'http://127.0.0.1:19000';

    private const API_KEY = 'test-fee-delegation-api-key';

    private const TOKEN = '0x1111111111111111111111111111111111111111';

    private const RECIPIENT = '0x2222222222222222222222222222222222222222';

    private const SENDER = '0x3333333333333333333333333333333333333333';

    private const OTHER_ADDRESS = '0x4444444444444444444444444444444444444444';

    private const TX_HASH = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const SDK_TOKEN = '0xe7c3d8c9a439fede00d2600032d5db0be71c3c29';

    private const SDK_RECIPIENT = '0x70997970c51812dc3a010c7d01b50e0d17dc79c8';

    private const SDK_SENDER = '0xa2a8854b1802d8cd5de631e690817c253d6a9153';

    private const SDK_SIGNED_TRANSACTION = '0x31f8c50185066720b300830186a094e7c3d8c9a439fede00d2600032d5db0be71c3c298094a2a8854b1802d8cd5de631e690817c253d6a9153b844a9059cbb00000000000000000000000070997970c51812dc3a010c7d01b50e0d17dc79c80000000000000000000000000000000000000000000000000de0b6b3a7640000f847f8458207f6a052baf55055acf5989c5fcae851e263f7a99bc2403210ebb4e70bcebc7945bed8a0755eadf37734c3f1629836ed938782dd1e40d4ece67910a84c2dafe234fe8c6d';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'blockchain.network' => 'kairos',
            'blockchain.profiles.kairos.chain_id' => 1001,
            'blockchain.profiles.kairos.rpc_url' => self::RPC_URL,
            'blockchain.profiles.kairos.jpyc.contract' => self::TOKEN,
            'blockchain.profiles.kairos.jpyc.decimals' => 18,
            'services.fee_delegation.enabled' => true,
            'services.fee_delegation.mode' => 'self-hosted',
            'services.fee_delegation.url' => self::FEE_DELEGATION_URL,
            'services.fee_delegation.api_key' => self::API_KEY,
            'services.fee_delegation.max_gas' => 150000,
            'services.fee_delegation.timeout_seconds' => 30,
        ]);

        Http::preventStrayRequests();

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = ON');
        }
    }

    public function test_valid_transaction_is_sponsored_without_finalizing_payment(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $raw = $this->senderSignedTransaction($payment->amount);
        $baselineLevel = DB::transactionLevel();
        $levels = [];
        $transactionsStarted = 0;

        Event::listen(
            TransactionBeginning::class,
            function () use (&$transactionsStarted): void {
                $transactionsStarted++;
            }
        );
        $this->fakeExternalServices($levels);

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $raw,
        ])
            ->assertOk()
            ->assertExactJson(['transaction_hash' => self::TX_HASH]);

        $this->assertPaymentPending($payment);
        $this->assertSame([$baselineLevel, $baselineLevel], $levels);
        $this->assertSame(2, $transactionsStarted);
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) use ($raw): bool {
            return $request->url() === self::RPC_URL
                && $request['method'] === 'kaia_recoverFromTransaction'
                && $request['params'][1] === 'latest'
                && $request['params'][0] !== $raw
                && str_starts_with($request['params'][0], '0x31')
                && strlen($request['params'][0]) > strlen($raw);
        });
        Http::assertSent(function (Request $request) use ($raw): bool {
            return $request->url()
                    === self::FEE_DELEGATION_URL.'/api/signAsFeePayer'
                && $request->hasHeader('Authorization', 'Bearer '.self::API_KEY)
                && $request['userSignedTx'] === ['raw' => $raw];
        });
        $attempt = PaymentFeeDelegationAttempt::where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame('self-hosted', $attempt->provider);
        $this->assertSame('submitted', $attempt->state);
        $this->assertSame(self::TX_HASH, $attempt->tx_hash);
        $this->assertSame(hash('sha256', strtolower($raw)), $attempt->request_fingerprint);
        $this->assertMatchesRegularExpression('/\A0x[0-9a-f]{64}\z/', $attempt->sender_tx_hash);
    }

    public function test_successful_sponsorship_is_idempotent_for_the_same_sender_transaction(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $raw = $this->senderSignedTransaction($payment->amount);
        $this->fakeExternalServices();

        foreach ([1, 2] as $attempt) {
            $this->postJson("/api/payments/{$payment->id}/sponsor", [
                'sender_signed_tx' => $raw,
            ])
                ->assertOk()
                ->assertExactJson(['transaction_hash' => self::TX_HASH]);
        }

        $this->assertPaymentPending($payment);
        Http::assertSentCount(3);
        $this->assertSame(1, $this->feePayerRequestCount());
    }

    public function test_different_sender_transaction_is_rejected_after_sponsorship(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $this->fakeExternalServices();

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $this->senderSignedTransaction(
                $payment->amount
            ),
        ])->assertOk();

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $this->senderSignedTransaction(
                $payment->amount,
                nonce: '2'
            ),
        ])
            ->assertConflict()
            ->assertExactJson([
                'error' => 'Fee sponsorship already requested',
            ]);

        $this->assertPaymentPending($payment);
        Http::assertSentCount(3);
        $this->assertSame(1, $this->feePayerRequestCount());
    }

    public function test_transaction_hash_response_variant_is_accepted(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $raw = $this->senderSignedTransaction($payment->amount);

        Http::fake(function (Request $request) {
            if ($request->url() === self::RPC_URL) {
                return $this->recoveredSenderResponse();
            }

            return Http::response([
                'status' => true,
                'data' => [
                    'status' => '0x1',
                    'transactionHash' => '0x'.strtoupper(
                        substr(self::TX_HASH, 2)
                    ),
                ],
            ]);
        });

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $raw,
        ])->assertExactJson(['transaction_hash' => self::TX_HASH]);

        $this->assertPaymentPending($payment);
    }

    public function test_official_sdk_sender_signed_transaction_matches_backend_policy(): void
    {
        config([
            'blockchain.profiles.kairos.jpyc.contract' => self::SDK_TOKEN,
        ]);
        $payment = $this->createPayment(recipient: self::SDK_RECIPIENT);
        $this->authenticateUser();

        Http::fake(function (Request $request) {
            if ($request->url() === self::RPC_URL) {
                return $this->recoveredSenderResponse(self::SDK_SENDER);
            }

            return Http::response([
                'status' => true,
                'data' => ['status' => 1, 'hash' => self::TX_HASH],
            ]);
        });

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => self::SDK_SIGNED_TRANSACTION,
        ])
            ->assertOk()
            ->assertExactJson(['transaction_hash' => self::TX_HASH]);

        $this->assertPaymentPending($payment);
        Http::assertSentCount(2);
    }

    public function test_managed_gateway_is_selected_explicitly_with_documented_contract(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $raw = $this->senderSignedTransaction($payment->amount);
        $this->enableManagedLiveTest($payment);

        Http::fake(function (Request $request) {
            if ($request->url() === self::RPC_URL) {
                return $this->recoveredSenderResponse();
            }

            return Http::response([
                'message' => 'Request was successful',
                'data' => ['status' => 1, 'hash' => self::TX_HASH],
                'status' => true,
            ]);
        });

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $raw,
        ])->assertExactJson(['transaction_hash' => self::TX_HASH]);

        $this->assertDatabaseHas('payment_fee_delegation_attempts', [
            'payment_id' => $payment->id,
            'provider' => 'kaia-managed',
            'state' => 'submitted',
        ]);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::MANAGED_FEE_DELEGATION_URL.'/api/signAsFeePayer'
            && $request->hasHeader('Authorization', 'Bearer '.self::API_KEY)
            && $request['userSignedTx'] === ['raw' => $raw]
        );
    }

    public function test_managed_gateway_requires_tls_and_api_key(): void
    {
        $this->authenticateUser();

        foreach ([
            ['https://fee.example.test', null],
            ['http://127.0.0.1:19000', self::API_KEY],
        ] as $index => [$url, $apiKey]) {
            $payment = $this->createPayment(suffix: "managed-config-{$index}");
            config([
                'services.fee_delegation.mode' => 'kaia-managed',
                'services.fee_delegation.url' => $url,
                'services.fee_delegation.api_key' => $apiKey,
            ]);
            Http::fake(fn () => $this->recoveredSenderResponse());

            $response = $this->postJson("/api/payments/{$payment->id}/sponsor", [
                'sender_signed_tx' => $this->senderSignedTransaction($payment->amount),
            ])->assertInternalServerError();

            $this->assertStringNotContainsString(self::API_KEY, $response->getContent());
            $this->assertDatabaseMissing('payment_fee_delegation_attempts', [
                'payment_id' => $payment->id,
            ]);
        }
    }

    public function test_unsupported_mode_fails_closed_and_app_env_does_not_select_provider(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        config([
            'app.env' => 'production',
            'services.fee_delegation.mode' => 'unsupported',
        ]);
        Http::fake(fn () => $this->recoveredSenderResponse());

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $this->senderSignedTransaction($payment->amount),
        ])->assertInternalServerError();

        $this->assertDatabaseMissing('payment_fee_delegation_attempts', [
            'payment_id' => $payment->id,
        ]);
        $this->assertSame('unsupported', config('services.fee_delegation.mode'));
    }

    public function test_managed_malformed_response_becomes_persistent_unknown_without_retry(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $raw = $this->senderSignedTransaction($payment->amount);
        $this->enableManagedLiveTest($payment);

        Http::fake(fn (Request $request) => $request->url() === self::RPC_URL
            ? $this->recoveredSenderResponse()
            : Http::response([
                'status' => true,
                'data' => ['status' => 1, 'hash' => self::TX_HASH],
            ]));

        foreach ([1, 2] as $ignored) {
            $this->postJson("/api/payments/{$payment->id}/sponsor", [
                'sender_signed_tx' => $raw,
            ])->assertServiceUnavailable();
        }

        $this->assertDatabaseHas('payment_fee_delegation_attempts', [
            'payment_id' => $payment->id,
            'provider' => 'kaia-managed',
            'state' => 'unknown_submission',
            'diagnostic_code' => 'provider_status_unknown',
        ]);
        $this->assertSame(1, $this->feePayerRequestCount());
    }

    public function test_managed_ambiguous_http_statuses_are_not_treated_as_safe_to_retry(): void
    {
        $this->authenticateUser();
        $statuses = [409, 429, 500];
        $providerStatuses = $statuses;

        Http::fake(function (Request $request) use (&$providerStatuses) {
            if ($request->url() === self::RPC_URL) {
                return $this->recoveredSenderResponse();
            }

            return Http::response([
                'message' => 'Provider state is not authoritative',
                'status' => false,
                'error' => 'INTERNAL_ERROR',
            ], array_shift($providerStatuses));
        });

        foreach ($statuses as $index => $status) {
            $payment = $this->createPayment(suffix: "managed-http-{$status}");
            $this->enableManagedLiveTest($payment);

            $this->postJson("/api/payments/{$payment->id}/sponsor", [
                'sender_signed_tx' => $this->senderSignedTransaction(
                    $payment->amount,
                    nonce: (string) ($index + 20)
                ),
            ])->assertServiceUnavailable();

            $this->assertDatabaseHas('payment_fee_delegation_attempts', [
                'payment_id' => $payment->id,
                'state' => 'unknown_submission',
                'provider_http_status' => $status,
            ]);
        }
    }

    public function test_kairos_service_can_be_called_without_optional_api_key(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        config(['services.fee_delegation.api_key' => null]);

        Http::fake(function (Request $request) {
            if ($request->url() === self::RPC_URL) {
                return $this->recoveredSenderResponse();
            }

            $this->assertFalse($request->hasHeader('Authorization'));

            return Http::response([
                'status' => true,
                'data' => ['status' => 1, 'hash' => self::TX_HASH],
            ]);
        });

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $this->senderSignedTransaction(
                $payment->amount
            ),
        ])->assertExactJson(['transaction_hash' => self::TX_HASH]);

        $this->assertPaymentPending($payment);
    }

    public function test_authenticated_loopback_sidecar_receives_the_original_sender_transaction(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $raw = $this->senderSignedTransaction($payment->amount);
        config([
            'services.fee_delegation.url' => self::LOOPBACK_FEE_PAYER_URL,
            'services.fee_delegation.api_key' => self::API_KEY,
        ]);

        Http::fake(function (Request $request) {
            if ($request->url() === self::RPC_URL) {
                return $this->recoveredSenderResponse();
            }

            return Http::response([
                'status' => true,
                'data' => ['status' => '0x1', 'hash' => self::TX_HASH],
            ]);
        });

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $raw,
        ])
            ->assertOk()
            ->assertExactJson(['transaction_hash' => self::TX_HASH]);

        $this->assertPaymentPending($payment);
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) use ($raw): bool {
            return $request->url()
                    === self::LOOPBACK_FEE_PAYER_URL.'/api/signAsFeePayer'
                && $request->hasHeader(
                    'Authorization',
                    'Bearer '.self::API_KEY
                )
                && $request['userSignedTx'] === ['raw' => $raw];
        });
    }

    public function test_unsafe_http_fee_payer_endpoints_are_rejected_before_sidecar_access(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $raw = $this->senderSignedTransaction($payment->amount);
        $cases = [
            ['http://127.0.0.1:19000', null],
            ['http://localhost:19000', self::API_KEY],
            ['http://127.0.0.2:19000', self::API_KEY],
            ['http://fee-payer.example.test', self::API_KEY],
            ['http://127.0.0.1:19000/path', self::API_KEY],
            ['http://127.0.0.1:19000', "invalid\nkey"],
        ];

        Http::fake(fn (Request $request) => $request->url() === self::RPC_URL
            ? $this->recoveredSenderResponse()
            : Http::response([], 500));

        foreach ($cases as [$url, $apiKey]) {
            config([
                'services.fee_delegation.url' => $url,
                'services.fee_delegation.api_key' => $apiKey,
            ]);

            $this->postJson("/api/payments/{$payment->id}/sponsor", [
                'sender_signed_tx' => $raw,
            ])
                ->assertInternalServerError()
                ->assertExactJson([
                    'error' => 'Fee sponsorship is unavailable',
                ]);
        }

        $this->assertPaymentPending($payment);
        Http::assertSentCount(count($cases));
        Http::assertSent(
            fn (Request $request): bool => $request->url() === self::RPC_URL
        );
    }

    public function test_unauthenticated_request_does_not_access_external_services(): void
    {
        $payment = $this->createPayment();

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $this->senderSignedTransaction(
                $payment->amount
            ),
        ])->assertUnauthorized();

        $this->assertPaymentPending($payment);
        Http::assertNothingSent();
    }

    public function test_token_without_payment_confirm_ability_is_forbidden(): void
    {
        $payment = $this->createPayment();
        $user = User::create([
            'name' => 'Wrong Ability User',
            'email' => 'wrong-ability@example.test',
            'password' => 'not-used',
        ]);
        Sanctum::actingAs($user, ['user:read']);

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $this->senderSignedTransaction(
                $payment->amount
            ),
        ])->assertForbidden();

        $this->assertPaymentPending($payment);
        Http::assertNothingSent();
    }

    public function test_missing_payment_is_rejected_before_external_access(): void
    {
        $this->authenticateUser();

        $this->postJson('/api/payments/999999999/sponsor', [
            'sender_signed_tx' => $this->senderSignedTransaction(1),
        ])
            ->assertNotFound()
            ->assertExactJson(['error' => 'Payment not found']);

        Http::assertNothingSent();
    }

    public function test_disabled_sponsorship_fails_before_external_access(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        config(['services.fee_delegation.enabled' => false]);

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $this->senderSignedTransaction(
                $payment->amount
            ),
        ])
            ->assertServiceUnavailable()
            ->assertExactJson(['error' => 'Fee sponsorship is unavailable']);

        $this->assertPaymentPending($payment);
        Http::assertNothingSent();
    }

    public function test_non_kairos_configuration_is_rejected(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        config(['blockchain.network' => 'kaia-mainnet']);

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $this->senderSignedTransaction(
                $payment->amount
            ),
        ])->assertInternalServerError();

        $this->assertPaymentPending($payment);
        Http::assertNothingSent();
    }

    public function test_expired_payment_is_rejected_before_external_access(): void
    {
        $payment = $this->createPayment([
            'expires_at' => now()->subSecond(),
        ]);
        $this->authenticateUser();

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $this->senderSignedTransaction(
                $payment->amount
            ),
        ])
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Expired']);

        $this->assertPaymentPending($payment);
        Http::assertNothingSent();
    }

    public function test_payment_without_expiry_is_not_sponsored(): void
    {
        $payment = $this->createPayment(['expires_at' => null]);
        $this->authenticateUser();

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $this->senderSignedTransaction(
                $payment->amount
            ),
        ])
            ->assertInternalServerError()
            ->assertExactJson(['error' => 'Fee sponsorship is unavailable']);

        $this->assertPaymentPending($payment);
        Http::assertNothingSent();
    }

    public function test_confirmed_payment_is_rejected_before_external_access(): void
    {
        $payment = $this->createPayment(['status' => 'confirmed']);
        $this->authenticateUser();

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $this->senderSignedTransaction(
                $payment->amount
            ),
        ])
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Already paid']);

        Http::assertNothingSent();
    }

    public function test_non_pending_payment_is_rejected_before_external_access(): void
    {
        $payment = $this->createPayment(['status' => 'failed']);
        $this->authenticateUser();

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $this->senderSignedTransaction(
                $payment->amount
            ),
        ])
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Payment not pending']);

        Http::assertNothingSent();
    }

    public function test_non_canonical_rlp_is_rejected_without_rpc_access(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();

        // Long-form string encoding for one byte is non-canonical RLP.
        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => '0x31b80100',
        ])
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Invalid sponsored transaction']);

        $this->assertPaymentPending($payment);
        Http::assertNothingSent();
    }

    public function test_excessive_rlp_nesting_is_rejected_without_rpc_access(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $raw = '0x31'.bin2hex($this->rlpEncode([[[[["\x01"]]]]]));

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $raw,
        ])
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Invalid sponsored transaction']);

        $this->assertPaymentPending($payment);
        Http::assertNothingSent();
    }

    public function test_wrong_transaction_type_and_chain_are_rejected(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();

        foreach ([
            $this->senderSignedTransaction($payment->amount, type: 0x30),
            $this->senderSignedTransaction($payment->amount, chainId: 8217),
        ] as $raw) {
            $this->postJson("/api/payments/{$payment->id}/sponsor", [
                'sender_signed_tx' => $raw,
            ])->assertBadRequest();
        }

        $this->assertPaymentPending($payment);
        Http::assertNothingSent();
    }

    public function test_integer_bounds_are_enforced_before_rpc_access(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        config([
            'services.fee_delegation.max_gas' => '18446744073709551616',
        ]);
        $cases = [
            $this->senderSignedTransaction(
                $payment->amount,
                nonce: '18446744073709551616'
            ),
            $this->senderSignedTransaction(
                $payment->amount,
                gasPrice: '0'
            ),
            $this->senderSignedTransaction(
                $payment->amount,
                gasPrice: '115792089237316195423570985008687907853269984665640564039457584007913129639936'
            ),
            $this->senderSignedTransaction(
                $payment->amount,
                gas: '18446744073709551616'
            ),
        ];

        foreach ($cases as $raw) {
            $this->postJson("/api/payments/{$payment->id}/sponsor", [
                'sender_signed_tx' => $raw,
            ])->assertBadRequest();
        }

        $this->assertPaymentPending($payment);
        Http::assertNothingSent();
    }

    public function test_wrong_token_recipient_amount_value_and_gas_are_rejected(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $rpcCases = [
            $this->senderSignedTransaction(
                $payment->amount,
                token: self::OTHER_ADDRESS
            ),
            $this->senderSignedTransaction(
                $payment->amount,
                recipient: self::OTHER_ADDRESS
            ),
            $this->senderSignedTransaction($payment->amount + 1),
        ];
        $localCases = [
            $this->senderSignedTransaction($payment->amount, value: '1'),
            $this->senderSignedTransaction($payment->amount, gas: '150001'),
        ];

        Http::fake(fn (Request $request) => $request->url() === self::RPC_URL
            ? $this->recoveredSenderResponse()
            : Http::response([], 500));

        foreach (array_merge($rpcCases, $localCases) as $raw) {
            $this->postJson("/api/payments/{$payment->id}/sponsor", [
                'sender_signed_tx' => $raw,
            ])->assertBadRequest();
        }

        $this->assertPaymentPending($payment);
        Http::assertSentCount(count($rpcCases));
        $this->assertSame(0, $this->feePayerRequestCount());
    }

    public function test_recovered_sender_mismatch_is_rejected_before_fee_payer(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();

        Http::fake(fn () => $this->recoveredSenderResponse(
            self::OTHER_ADDRESS
        ));

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $this->senderSignedTransaction(
                $payment->amount
            ),
        ])->assertBadRequest();

        $this->assertPaymentPending($payment);
        Http::assertSentCount(1);
        $this->assertSame(0, $this->feePayerRequestCount());
    }

    public function test_sender_recovery_rpc_failure_is_not_exposed(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();

        Http::fake(fn () => Http::response([
            'error' => ['code' => -32000, 'message' => 'private RPC detail'],
        ]));

        $response = $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $this->senderSignedTransaction(
                $payment->amount
            ),
        ]);

        $response
            ->assertInternalServerError()
            ->assertExactJson(['error' => 'Fee sponsorship is unavailable']);
        $this->assertStringNotContainsString(
            'private RPC detail',
            $response->getContent()
        );
        $this->assertPaymentPending($payment);
        Http::assertSentCount(1);
        $this->assertSame(0, $this->feePayerRequestCount());
    }

    public function test_invalid_signature_scalar_is_rejected_before_fee_payer(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $this->senderSignedTransaction(
                $payment->amount,
                signatureS: str_repeat('bb', 32)
            ),
        ])->assertBadRequest();

        $this->assertPaymentPending($payment);
        Http::assertNothingSent();
    }

    public function test_fee_payer_failure_is_not_retried_or_exposed(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();

        Http::fake(function (Request $request) {
            if ($request->url() === self::RPC_URL) {
                return $this->recoveredSenderResponse();
            }

            return Http::response([
                'status' => false,
                'message' => 'provider credentials and internal URL',
            ], 500);
        });

        $response = $this->postJson(
            "/api/payments/{$payment->id}/sponsor",
            ['sender_signed_tx' => $this->senderSignedTransaction(
                $payment->amount
            )]
        );

        $response
            ->assertServiceUnavailable()
            ->assertExactJson([
                'error' => 'Fee sponsorship status is unknown',
            ]);
        $this->assertStringNotContainsString(
            'provider credentials',
            $response->getContent()
        );
        $this->assertPaymentPending($payment);
        Http::assertSentCount(2);
    }

    public function test_fee_payer_connection_failure_has_unknown_status_and_is_not_retried(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();

        Http::fake(function (Request $request) {
            if ($request->url() === self::RPC_URL) {
                return $this->recoveredSenderResponse();
            }

            return (Http::failedConnection(
                'private provider URL omitted'
            ))($request);
        });

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $this->senderSignedTransaction(
                $payment->amount
            ),
        ])
            ->assertServiceUnavailable()
            ->assertExactJson([
                'error' => 'Fee sponsorship status is unknown',
            ]);

        $this->assertPaymentPending($payment);
        Http::assertSentCount(2);
    }

    public function test_explicit_reverted_fee_payer_response_is_not_resubmitted(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $responses = [Http::response([
            'status' => true,
            'data' => ['status' => 0, 'hash' => self::TX_HASH],
        ])];
        $index = 0;

        Http::fake(function (Request $request) use (&$index, $responses) {
            if ($request->url() === self::RPC_URL) {
                return $this->recoveredSenderResponse();
            }

            return $responses[$index++];
        });

        $payload = ['sender_signed_tx' => $this->senderSignedTransaction(
            $payment->amount
        )];

        $this->postJson("/api/payments/{$payment->id}/sponsor", $payload)
            ->assertStatus(502)
            ->assertExactJson(['error' => 'Fee sponsorship rejected']);
        $this->postJson("/api/payments/{$payment->id}/sponsor", $payload)
            ->assertStatus(502)
            ->assertExactJson(['error' => 'Fee sponsorship rejected']);

        $this->assertPaymentPending($payment);
        Http::assertSentCount(3);
        $this->assertSame(1, $this->feePayerRequestCount());
        $this->assertDatabaseHas('payment_fee_delegation_attempts', [
            'payment_id' => $payment->id,
            'state' => 'reverted',
            'diagnostic_code' => 'transaction_reverted',
        ]);
    }

    public function test_malformed_fee_payer_response_blocks_duplicate_submission(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();
        $raw = $this->senderSignedTransaction($payment->amount);

        Http::fake(fn (Request $request) => $request->url() === self::RPC_URL
            ? $this->recoveredSenderResponse()
            : Http::response('not-json'));

        foreach ([1, 2] as $attempt) {
            $this->postJson("/api/payments/{$payment->id}/sponsor", [
                'sender_signed_tx' => $raw,
            ])
                ->assertServiceUnavailable()
                ->assertExactJson([
                    'error' => 'Fee sponsorship status is unknown',
                ]);
        }

        $this->assertPaymentPending($payment);
        Http::assertSentCount(3);
        $this->assertSame(1, $this->feePayerRequestCount());
    }

    public function test_ambiguous_fee_payer_responses_have_unknown_status(): void
    {
        $this->authenticateUser();
        $responses = [
            Http::response([
                'data' => ['status' => 1, 'hash' => self::TX_HASH],
            ]),
            Http::response([
                'status' => true,
                'data' => ['hash' => self::TX_HASH],
            ]),
            Http::response([
                'status' => false,
                'error' => 'INTERNAL_ERROR',
            ]),
            Http::response([
                'status' => false,
                'error' => 'BAD_REQUEST',
                'data' => ['status' => 1, 'hash' => self::TX_HASH],
            ]),
            Http::response([
                'status' => true,
                'data' => ['status' => 1, 'hash' => 'invalid'],
            ]),
        ];
        $index = 0;

        Http::fake(function (Request $request) use (&$index, $responses) {
            if ($request->url() === self::RPC_URL) {
                return $this->recoveredSenderResponse();
            }

            return $responses[$index++];
        });

        foreach (array_keys($responses) as $case) {
            $payment = $this->createPayment(suffix: "ambiguous-{$case}");

            $this->postJson("/api/payments/{$payment->id}/sponsor", [
                'sender_signed_tx' => $this->senderSignedTransaction(
                    $payment->amount,
                    nonce: (string) ($case + 1)
                ),
            ])
                ->assertServiceUnavailable()
                ->assertExactJson([
                    'error' => 'Fee sponsorship status is unknown',
                ]);

            $this->assertPaymentPending($payment);
        }

        Http::assertSentCount(count($responses) * 2);
    }

    public function test_explicit_provider_bad_request_is_rejected(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();

        Http::fake(fn (Request $request) => $request->url() === self::RPC_URL
            ? $this->recoveredSenderResponse()
            : Http::response([
                'status' => false,
                'error' => 'BAD_REQUEST',
            ]));

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $this->senderSignedTransaction(
                $payment->amount
            ),
        ])
            ->assertStatus(502)
            ->assertExactJson(['error' => 'Fee sponsorship rejected']);

        $this->assertPaymentPending($payment);
        Http::assertSentCount(2);
    }

    public function test_conflicting_provider_hashes_have_unknown_status(): void
    {
        $payment = $this->createPayment();
        $this->authenticateUser();

        Http::fake(fn (Request $request) => $request->url() === self::RPC_URL
            ? $this->recoveredSenderResponse()
            : Http::response([
                'status' => true,
                'data' => [
                    'status' => 1,
                    'hash' => self::TX_HASH,
                    'transactionHash' => '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                ],
            ]));

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $this->senderSignedTransaction(
                $payment->amount
            ),
        ])
            ->assertServiceUnavailable()
            ->assertExactJson([
                'error' => 'Fee sponsorship status is unknown',
            ]);

        $this->assertPaymentPending($payment);
        Http::assertSentCount(2);
    }

    public function test_unknown_resolver_not_found_does_not_resubmit(): void
    {
        [$payment, $attempt] = $this->createUnknownAttempt();

        $this->resolverResults = [true, null, null];

        $result = app(FeeDelegationAttemptResolver::class)->resolve($attempt);

        $this->assertSame(FeeDelegationAttemptResolver::NOT_FOUND, $result);
        $this->assertDatabaseHas('payment_fee_delegation_attempts', [
            'payment_id' => $payment->id,
            'state' => 'unknown_submission',
            'diagnostic_code' => 'sender_tx_not_found',
        ]);
        Http::assertSentCount(5);
        $this->assertSame(1, $this->feePayerRequestCount());
    }

    public function test_unknown_resolver_finds_full_transaction_without_broadcasting(): void
    {
        [$payment, $attempt] = $this->createUnknownAttempt();
        $atomicHex = gmp_strval(gmp_init('1000000000000000000', 10), 16);
        $expectedInput = '0xa9059cbb'.str_repeat('0', 24)
            .substr(self::RECIPIENT, 2)
            .str_pad($atomicHex, 64, '0', STR_PAD_LEFT);

        $this->resolverResults = [
            true,
            [
                'hash' => self::TX_HASH,
                'senderTxHash' => $attempt->sender_tx_hash,
                'from' => self::SENDER,
                'to' => self::TOKEN,
                'input' => $expectedInput,
                'value' => '0x0',
                'nonce' => '0x1',
                'type' => 'TxTypeFeeDelegatedSmartContractExecution',
                'typeInt' => 49,
            ],
            null,
        ];

        $result = app(FeeDelegationAttemptResolver::class)->resolve($attempt);

        $this->assertSame(FeeDelegationAttemptResolver::SUBMITTED, $result);
        $this->assertDatabaseHas('payment_fee_delegation_attempts', [
            'payment_id' => $payment->id,
            'state' => 'submitted',
            'tx_hash' => self::TX_HASH,
            'diagnostic_code' => 'receipt_pending',
        ]);
        Http::assertSentCount(5);
    }

    public function test_unknown_resolver_transport_failure_preserves_unknown(): void
    {
        [$payment, $attempt] = $this->createUnknownAttempt();
        $this->resolverResults = ['connection_failure'];

        $result = app(FeeDelegationAttemptResolver::class)->resolve($attempt);

        $this->assertSame(FeeDelegationAttemptResolver::TRANSIENT_FAILURE, $result);
        $this->assertDatabaseHas('payment_fee_delegation_attempts', [
            'payment_id' => $payment->id,
            'state' => 'unknown_submission',
            'diagnostic_code' => 'sender_tx_lookup_unavailable',
        ]);
    }

    private function createPayment(
        array $overrides = [],
        string $recipient = self::RECIPIENT,
        string $suffix = 'default'
    ): Payment {
        $store = Store::create([
            'store_code' => "sponsorship-test-store-{$suffix}",
            'store_pin' => 'sponsorship-test-pin',
            'name' => 'Sponsorship Test Store',
        ]);
        $staff = Staff::create([
            'store_id' => $store->id,
            'staff_id' => "sponsorship-test-staff-{$suffix}",
            'name' => 'Sponsorship Test Staff',
            'pin' => 'sponsorship-test-staff-pin',
            'role' => 'staff',
        ]);
        Wallet::create([
            'store_id' => $store->id,
            'address' => $recipient,
            'network' => 'kairos',
        ]);

        $attributes = array_merge([
            'store_id' => $store->id,
            'staff_id' => $staff->id,
            'amount' => 1,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ], $overrides);

        if ($attributes['expires_at'] === null) {
            return Payment::create($attributes);
        }

        $snapshot = PaymentSnapshot::create(
            app(NetworkProfileRegistry::class)->get('kairos'),
            $recipient,
            $attributes['amount'],
            $attributes['expires_at']
        );

        return Payment::create(array_merge(
            $attributes,
            $snapshot->databaseAttributes(),
            $overrides
        ));
    }

    /** @return array{Payment, PaymentFeeDelegationAttempt} */
    private function createUnknownAttempt(): array
    {
        $payment = $this->createPayment(suffix: uniqid('resolver-', true));
        $this->authenticateUser();
        Http::fake(function (Request $request) {
            if ($request->url() === self::RPC_URL) {
                if ($request['method'] === 'kaia_recoverFromTransaction') {
                    return $this->recoveredSenderResponse();
                }

                $result = array_shift($this->resolverResults);

                if ($result === 'connection_failure') {
                    return (Http::failedConnection('redacted RPC failure'))($request);
                }

                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => $result,
                ]);
            }

            return Http::response([
                'status' => false,
                'error' => 'INTERNAL_ERROR',
            ], 500);
        });

        $this->postJson("/api/payments/{$payment->id}/sponsor", [
            'sender_signed_tx' => $this->senderSignedTransaction($payment->amount),
        ])->assertServiceUnavailable();

        Http::assertSentCount(2);

        return [
            $payment,
            PaymentFeeDelegationAttempt::where('payment_id', $payment->id)->firstOrFail(),
        ];
    }

    private function authenticateUser(): User
    {
        $user = User::create([
            'name' => 'Sponsorship Test User',
            'email' => 'sponsorship@example.test',
            'password' => 'not-used',
        ]);
        Sanctum::actingAs($user, ['payment:confirm']);

        return $user;
    }

    private function fakeExternalServices(?array &$levels = null): void
    {
        Http::fake(function (Request $request) use (&$levels) {
            if ($levels !== null) {
                $levels[] = DB::transactionLevel();
            }

            if ($request->url() === self::RPC_URL) {
                return $this->recoveredSenderResponse();
            }

            return Http::response([
                'status' => true,
                'data' => ['status' => 1, 'hash' => self::TX_HASH],
            ]);
        });
    }

    private function recoveredSenderResponse(
        string $sender = self::SENDER
    ): mixed {
        return Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => $sender,
        ]);
    }

    private function feePayerRequestCount(): int
    {
        return Http::recorded(
            fn (Request $request): bool => $request->url()
                === rtrim((string) config('services.fee_delegation.url'), '/')
                    .'/api/signAsFeePayer'
        )->count();
    }

    private function enableManagedLiveTest(Payment $payment): void
    {
        config([
            'services.fee_delegation.mode' => 'kaia-managed',
            'services.fee_delegation.url' => self::MANAGED_FEE_DELEGATION_URL,
            'services.fee_delegation.kairos_managed_live_test_enabled' => true,
            'services.fee_delegation.kairos_managed_live_test_payment_id' => $payment->id,
            'services.fee_delegation.kairos_managed_live_test_max_jpy' => 1,
        ]);
    }

    private function senderSignedTransaction(
        int $amount,
        int $type = 0x31,
        int $chainId = 1001,
        string $token = self::TOKEN,
        string $recipient = self::RECIPIENT,
        string $value = '0',
        string $gas = '100000',
        string $nonce = '1',
        string $gasPrice = '25000000000',
        string $signatureR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        string $signatureS = '2222222222222222222222222222222222222222222222222222222222222222'
    ): string {
        $atomicAmount = bcmul((string) $amount, bcpow('10', '18', 0), 0);
        $input = hex2bin('a9059cbb')
            .str_repeat("\0", 12)
            .hex2bin(substr($recipient, 2))
            .$this->uintWord($atomicAmount);
        $v = (string) (($chainId * 2) + 35);
        $fields = [
            $this->uintBytes($nonce),
            $this->uintBytes($gasPrice),
            $this->uintBytes($gas),
            hex2bin(substr($token, 2)),
            $this->uintBytes($value),
            hex2bin(substr(self::SENDER, 2)),
            $input,
            [
                [
                    $this->uintBytes($v),
                    hex2bin($signatureR),
                    hex2bin($signatureS),
                ],
            ],
        ];

        return '0x'.str_pad(dechex($type), 2, '0', STR_PAD_LEFT)
            .bin2hex($this->rlpEncode($fields));
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

        $length = strlen($value);

        if ($length === 1 && ord($value[0]) <= 0x7F) {
            return $value;
        }

        return $this->rlpPrefix($length, 0x80, 0xB7).$value;
    }

    private function rlpPrefix(
        int $length,
        int $shortOffset,
        int $longOffset
    ): string {
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

        if (strlen($hex) % 2 !== 0) {
            $hex = '0'.$hex;
        }

        return hex2bin($hex);
    }

    private function uintWord(string $decimal): string
    {
        return str_pad(
            $this->uintBytes($decimal),
            32,
            "\0",
            STR_PAD_LEFT
        );
    }

    private function assertPaymentPending(Payment $payment): void
    {
        $payment->refresh();
        $this->assertSame('pending', $payment->status);
        $this->assertNull($payment->tx_hash);
        $this->assertNull($payment->user_id);
        $this->assertNull($payment->paid_at);
    }
}
