<?php

namespace Tests\Feature;

use App\Blockchain\NetworkProfileRegistry;
use App\Models\Payment;
use App\Models\Staff;
use App\Models\Store;
use App\Payments\PaymentHardeningPreflight;
use App\Payments\PaymentMigrationAuditService;
use App\Payments\PaymentSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentMigrationAuditTest extends TestCase
{
    use RefreshDatabase;

    private const TX_HASH = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const RECIPIENT = '0x2222222222222222222222222222222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = ON');
        }
    }

    public function test_complete_snapshot_is_valid_and_absent_or_partial_snapshot_is_not(): void
    {
        $valid = $this->paymentWithSnapshot();
        $absent = $this->legacyPayment();
        $partial = $this->legacyPayment();
        DB::table('payments')->where('id', $partial->id)->update(['network' => 'kairos']);

        $result = app(PaymentMigrationAuditService::class)->audit();

        $this->assertSame([$valid->id], array_column($result['A_valid'], 'id'));
        $this->assertContains($absent->id, array_column($result['C_ambiguous'], 'id'));
        $this->assertSame([$partial->id], array_column($result['D_invalid'], 'id'));
    }

    public function test_manifest_backfill_is_dry_run_by_default(): void
    {
        $payment = $this->legacyPayment();
        $manifest = $this->manifest($payment->id);

        $changes = app(PaymentMigrationAuditService::class)->backfill($manifest);

        $this->assertFalse($changes[0]['applied']);
        $this->assertNull($payment->fresh()->network);
    }

    public function test_explicit_backfill_changes_only_eligible_kairos_rows(): void
    {
        $eligible = $this->legacyPayment();
        $ambiguous = $this->legacyPayment();
        $manifest = $this->manifest($eligible->id);
        $manifest[(string) $ambiguous->id] = array_merge(
            $manifest[(string) $eligible->id],
            ['network' => 'kaia-mainnet']
        );

        $changes = app(PaymentMigrationAuditService::class)->backfill($manifest, true);

        $this->assertSame([$eligible->id], array_column($changes, 'id'));
        $this->assertSame('kairos', $eligible->fresh()->network);
        $this->assertNull($ambiguous->fresh()->network);
        $this->assertNull($eligible->fresh()->observed_chain_id);
    }

    public function test_same_chain_duplicate_is_rejected_but_cross_chain_hash_is_allowed(): void
    {
        $this->paymentWithSnapshot(['tx_hash' => self::TX_HASH]);

        try {
            $this->paymentWithSnapshot(['tx_hash' => self::TX_HASH]);
            $this->fail('Same-chain duplicate transaction hash was accepted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->paymentWithSnapshot([
            'tx_hash' => self::TX_HASH,
            'network' => 'kaia-mainnet',
        ]);

        $this->assertSame(2, DB::table('payments')->where('tx_hash', self::TX_HASH)->count());
    }

    public function test_multiple_pending_null_hashes_remain_valid_and_chain_id_is_not_invented(): void
    {
        $this->paymentWithSnapshot();
        $this->paymentWithSnapshot();
        $legacy = $this->legacyPayment();

        $this->assertSame(2, DB::table('payments')->whereNotNull('chain_id')->count());
        $this->assertNull($legacy->fresh()->chain_id);
    }

    public function test_hardening_preflight_rejects_missing_snapshot_and_invalid_confirmed_evidence(): void
    {
        $this->legacyPayment();

        $this->expectException(\RuntimeException::class);
        app(PaymentHardeningPreflight::class)->assertSafe();
    }

    public function test_hardening_preflight_rejects_confirmed_row_without_evidence(): void
    {
        $this->paymentWithSnapshot(['status' => 'confirmed']);

        $this->expectException(\RuntimeException::class);
        app(PaymentHardeningPreflight::class)->assertSafe();
    }

    public function test_hardening_preflight_detects_same_chain_duplicates(): void
    {
        Schema::table('payments', function ($table) {
            $table->dropUnique('payments_chain_id_tx_hash_unique');
        });
        $this->paymentWithSnapshot(['tx_hash' => self::TX_HASH]);
        $this->paymentWithSnapshot(['tx_hash' => self::TX_HASH]);

        $this->expectException(\RuntimeException::class);
        app(PaymentHardeningPreflight::class)->assertSafe();
    }

    private function paymentWithSnapshot(array $overrides = []): Payment
    {
        $network = $overrides['network'] ?? 'kairos';
        $base = $this->basePayment();
        $snapshot = PaymentSnapshot::create(
            app(NetworkProfileRegistry::class)->get($network),
            self::RECIPIENT,
            125,
            now()->addMinutes(10),
        );

        unset($overrides['network']);

        return Payment::create(array_merge(
            $base,
            $snapshot->databaseAttributes(),
            $overrides,
        ));
    }

    private function legacyPayment(): Payment
    {
        return Payment::create($this->basePayment());
    }

    /** @return array<string, mixed> */
    private function basePayment(): array
    {
        $store = Store::create([
            'store_code' => 'audit-store-'.uniqid(),
            'store_pin' => 'audit-store-pin',
            'name' => 'Audit Store',
        ]);
        $staff = Staff::create([
            'store_id' => $store->id,
            'staff_id' => 'audit-staff-'.uniqid(),
            'name' => 'Audit Staff',
            'pin' => 'audit-staff-pin',
            'role' => 'staff',
        ]);

        return [
            'store_id' => $store->id,
            'staff_id' => $staff->id,
            'amount' => 125,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ];
    }

    /** @return array<string, array<string, string>> */
    private function manifest(int $paymentId): array
    {
        return [(string) $paymentId => [
            'source' => 'trusted_historical_export',
            'source_reference' => 'sha256:'.str_repeat('b', 64),
            'network' => 'kairos',
            'recipient_address' => self::RECIPIENT,
        ]];
    }
}
