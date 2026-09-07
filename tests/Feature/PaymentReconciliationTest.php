<?php

namespace Tests\Feature;

use App\Blockchain\NetworkProfileRegistry;
use App\Models\Payment;
use App\Models\Staff;
use App\Models\Store;
use App\Payments\PaymentConfirmationEvidence;
use App\Payments\PaymentSnapshot;
use App\Services\Payments\PaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PaymentReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const RPC_URL = 'https://rpc.example.test';

    private const TOKEN = '0x1111111111111111111111111111111111111111';

    private const RECIPIENT = '0x2222222222222222222222222222222222222222';

    private const PAYER = '0x3333333333333333333333333333333333333333';

    private const TX_HASH = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const BLOCK_HASH = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-06T12:00:00Z');

        config([
            'blockchain.network' => 'kairos',
            'blockchain.profiles.kairos.rpc_url' => self::RPC_URL,
            'blockchain.profiles.kairos.chain_id' => 1001,
            'blockchain.profiles.kairos.jpyc.contract' => self::TOKEN,
            'blockchain.profiles.kairos.jpyc.decimals' => 18,
        ]);
        Http::preventStrayRequests();

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = ON');
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_valid_confirmed_payment_reconciles_without_replacing_evidence(): void
    {
        $payment = $this->confirmedPayment();
        $originalVerifiedAt = $payment->verified_at;
        $this->fakeEvidenceRpc();

        $result = app(PaymentReconciliationService::class)->reconcile($payment);

        $payment->refresh();
        $this->assertSame(PaymentReconciliationService::VERIFIED, $result);
        $this->assertSame('verified', $payment->reconciliation_status);
        $this->assertNull($payment->reconciliation_error_code);
        $this->assertNotNull($payment->reconciled_at);
        $this->assertSame(self::BLOCK_HASH, $payment->confirmed_block_hash);
        $this->assertTrue($originalVerifiedAt->equalTo($payment->verified_at));
    }

    public function test_changed_canonical_block_hash_is_recorded_as_anomaly(): void
    {
        $payment = $this->confirmedPayment();
        $otherHash = '0x'.str_repeat('c', 64);
        $this->fakeEvidenceRpc(blockHash: $otherHash);

        $result = app(PaymentReconciliationService::class)->reconcile($payment);

        $payment->refresh();
        $this->assertSame(PaymentReconciliationService::ANOMALY, $result);
        $this->assertSame('confirmation_evidence_mismatch', $payment->reconciliation_error_code);
        $this->assertSame(self::BLOCK_HASH, $payment->confirmed_block_hash);
    }

    public function test_missing_receipt_is_detected_without_erasing_original_evidence(): void
    {
        $payment = $this->confirmedPayment();
        $this->fakeEvidenceRpc(receipt: null);

        $result = app(PaymentReconciliationService::class)->reconcile($payment);

        $payment->refresh();
        $this->assertSame(PaymentReconciliationService::ANOMALY, $result);
        $this->assertSame('transaction_not_found', $payment->reconciliation_error_code);
        $this->assertSame(self::TX_HASH, $payment->tx_hash);
        $this->assertSame(self::BLOCK_HASH, $payment->confirmed_block_hash);
    }

    public function test_changed_transfer_is_recorded_as_anomaly(): void
    {
        $payment = $this->confirmedPayment();
        $receipt = $this->receipt(self::BLOCK_HASH);
        $receipt['logs'][0]['data'] = '0x'.str_pad('2', 64, '0', STR_PAD_LEFT);
        $this->fakeEvidenceRpc(receipt: $receipt);

        $result = app(PaymentReconciliationService::class)->reconcile($payment);

        $payment->refresh();
        $this->assertSame(PaymentReconciliationService::ANOMALY, $result);
        $this->assertSame('invalid_transaction', $payment->reconciliation_error_code);
        $this->assertSame('125000000000000000000', $payment->atomic_amount);
    }

    public function test_transient_rpc_failure_does_not_invalidate_or_replace_evidence(): void
    {
        $payment = $this->confirmedPayment();
        Log::spy();
        Http::fake(Http::failedConnection('Temporary RPC failure'));

        $result = app(PaymentReconciliationService::class)->reconcile($payment);

        $payment->refresh();
        $this->assertSame(PaymentReconciliationService::TRANSIENT_FAILURE, $result);
        $this->assertSame('transient_failure', $payment->reconciliation_status);
        $this->assertSame('rpc_transport_failure', $payment->reconciliation_error_code);
        $this->assertSame(self::TX_HASH, $payment->tx_hash);
        $this->assertSame(self::BLOCK_HASH, $payment->confirmed_block_hash);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_legacy_confirmed_payment_without_evidence_is_not_guessed(): void
    {
        $payment = $this->confirmedPayment();
        DB::table('payments')->where('id', $payment->id)->update([
            'observed_chain_id' => null,
            'confirmed_block_number' => null,
            'confirmed_block_hash' => null,
            'receipt_status' => null,
            'payer_address' => null,
            'transfer_log_index' => null,
            'chain_confirmed_at' => null,
            'verified_at' => null,
        ]);

        $result = app(PaymentReconciliationService::class)->reconcile($payment);

        $payment->refresh();
        $this->assertSame(PaymentReconciliationService::ANOMALY, $result);
        $this->assertSame(
            'confirmation_evidence_unavailable',
            $payment->reconciliation_error_code
        );
        Http::assertNothingSent();
    }

    private function confirmedPayment(): Payment
    {
        $store = Store::create([
            'store_code' => 'reconciliation-store',
            'store_pin' => 'test-pin',
            'name' => 'Reconciliation Store',
        ]);
        $staff = Staff::create([
            'store_id' => $store->id,
            'staff_id' => 'reconciliation-staff',
            'name' => 'Reconciliation Staff',
            'pin' => 'test-pin',
            'role' => 'staff',
        ]);
        $snapshot = PaymentSnapshot::create(
            app(NetworkProfileRegistry::class)->get('kairos'),
            self::RECIPIENT,
            125,
            now()->addMinutes(10)
        );
        $evidence = PaymentConfirmationEvidence::create(
            observedChainId: 1001,
            transactionHash: self::TX_HASH,
            blockNumber: 16,
            blockHash: self::BLOCK_HASH,
            receiptStatus: 1,
            payerAddress: self::PAYER,
            transferLogIndex: 0,
            chainConfirmedAt: now(),
            verifiedAt: now(),
        );

        return Payment::create(array_merge(
            [
                'store_id' => $store->id,
                'staff_id' => $staff->id,
                'amount' => 125,
                'status' => 'confirmed',
                'paid_at' => now(),
                'reconciliation_status' => 'pending',
            ],
            $snapshot->databaseAttributes(),
            $evidence->databaseAttributes()
        ));
    }

    private function fakeEvidenceRpc(
        mixed $receipt = '__default__',
        string $blockHash = self::BLOCK_HASH
    ): void {
        if ($receipt === '__default__') {
            $receipt = $this->receipt($blockHash);
        }

        Http::fake(function (Request $request) use ($receipt, $blockHash) {
            $result = match ($request['method']) {
                'eth_chainId' => '0x3e9',
                'eth_getTransactionByHash' => [
                    'hash' => self::TX_HASH,
                    'blockNumber' => '0x10',
                    'blockHash' => $blockHash,
                    'from' => self::PAYER,
                ],
                'eth_getTransactionReceipt' => $receipt,
                'eth_getBlockByNumber' => [
                    'number' => '0x10',
                    'hash' => $blockHash,
                    'timestamp' => '0x'.dechex(now()->timestamp),
                    'transactions' => [self::TX_HASH],
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

    private function receipt(string $blockHash): array
    {
        $amount = bcmul('125', bcpow('10', '18', 0), 0);

        return [
            'transactionHash' => self::TX_HASH,
            'blockNumber' => '0x10',
            'blockHash' => $blockHash,
            'status' => '0x1',
            'logs' => [[
                'address' => self::TOKEN,
                'topics' => [
                    self::TRANSFER_TOPIC,
                    $this->addressTopic(self::PAYER),
                    $this->addressTopic(self::RECIPIENT),
                ],
                'data' => '0x'.str_pad(
                    gmp_strval(gmp_init($amount, 10), 16),
                    64,
                    '0',
                    STR_PAD_LEFT
                ),
                'logIndex' => '0x0',
                'removed' => false,
            ]],
        ];
    }

    private function addressTopic(string $address): string
    {
        return '0x'.str_pad(substr($address, 2), 64, '0', STR_PAD_LEFT);
    }
}
