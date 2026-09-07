<?php

namespace App\Payments;

use App\Blockchain\NetworkProfileRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class PaymentMigrationAuditService
{
    public const VALID = 'A_valid';

    public const BACKFILLABLE = 'B_backfillable';

    public const AMBIGUOUS = 'C_ambiguous';

    public const INVALID = 'D_invalid';

    private const SNAPSHOT_FIELDS = [
        'network',
        'network_profile_version',
        'chain_id',
        'token_contract',
        'token_symbol',
        'token_decimals',
        'recipient_address',
        'display_amount',
        'atomic_amount',
    ];

    public function __construct(private readonly NetworkProfileRegistry $networks) {}

    /**
     * @param  array<int|string, array<string, mixed>>  $manifest
     * @return array<string, array<int, array{id: int, reason: string}>>
     */
    public function audit(array $manifest = []): array
    {
        $result = $this->emptyResult();
        $snapshotSchemaReady = Schema::hasColumns('payments', self::SNAPSHOT_FIELDS);

        foreach (DB::table('payments')->orderBy('id')->get() as $payment) {
            if (! $snapshotSchemaReady) {
                $result[self::AMBIGUOUS][] = [
                    'id' => (int) $payment->id,
                    'reason' => 'snapshot_schema_not_applied',
                ];

                continue;
            }

            [$category, $reason] = $this->classify(
                $payment,
                $manifest[(string) $payment->id] ?? $manifest[$payment->id] ?? null
            );
            $result[$category][] = ['id' => (int) $payment->id, 'reason' => $reason];
        }

        return $result;
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $manifest
     * @return array<int, array{id: int, applied: bool, sources: array<string, string>}>
     */
    public function backfill(array $manifest, bool $apply = false): array
    {
        if (! Schema::hasColumns('payments', self::SNAPSHOT_FIELDS)) {
            return [];
        }

        $changes = [];

        foreach ($manifest as $paymentId => $entry) {
            $payment = DB::table('payments')->find($paymentId);

            if ($payment === null || ! $this->snapshotIsEntirelyNull($payment)) {
                continue;
            }

            $snapshot = $this->snapshotFromManifest($payment, $entry);

            if ($snapshot === null) {
                continue;
            }

            $sources = [
                'network/profile/token' => 'approved_kairos_profile',
                'recipient_address' => (string) $entry['source_reference'],
                'display/atomic_amount' => 'legacy_payment.amount',
                'expires_at' => 'legacy_payment.expires_at',
                'confirmation_evidence' => 'not_backfilled',
            ];

            $applied = false;

            if ($apply) {
                DB::transaction(function () use ($paymentId, $snapshot, &$applied): void {
                    $locked = DB::table('payments')
                        ->where('id', $paymentId)
                        ->lockForUpdate()
                        ->first();

                    if ($locked === null || ! $this->snapshotIsEntirelyNull($locked)) {
                        return;
                    }

                    $applied = DB::table('payments')
                        ->where('id', $paymentId)
                        ->update($snapshot->databaseAttributes()) === 1;
                });
            }

            $changes[] = [
                'id' => (int) $paymentId,
                'applied' => $applied,
                'sources' => $sources,
            ];
        }

        return $changes;
    }

    /** @return array{string, string} */
    private function classify(object $payment, mixed $manifestEntry): array
    {
        $nullCount = 0;

        foreach (self::SNAPSHOT_FIELDS as $field) {
            $nullCount += $payment->{$field} === null ? 1 : 0;
        }

        if ($nullCount === count(self::SNAPSHOT_FIELDS)) {
            return is_array($manifestEntry)
                && $this->snapshotFromManifest($payment, $manifestEntry) !== null
                    ? [self::BACKFILLABLE, 'trusted_kairos_manifest']
                    : [self::AMBIGUOUS, 'snapshot_absent_no_trusted_source'];
        }

        if ($nullCount !== 0) {
            return [self::INVALID, 'partial_snapshot'];
        }

        try {
            PaymentSnapshot::fromRecord($payment);
        } catch (Throwable) {
            return [self::INVALID, 'snapshot_inconsistent'];
        }

        if ($payment->status === 'confirmed') {
            try {
                PaymentConfirmationEvidence::fromRecord($payment);
            } catch (Throwable) {
                return [self::AMBIGUOUS, 'confirmed_evidence_absent'];
            }
        }

        return [self::VALID, 'snapshot_and_evidence_valid'];
    }

    /** @param array<string, mixed> $entry */
    private function snapshotFromManifest(object $payment, array $entry): ?PaymentSnapshot
    {
        if (($entry['source'] ?? null) !== 'trusted_historical_export'
            || ! is_string($entry['source_reference'] ?? null)
            || preg_match('/\Asha256:[0-9a-f]{64}\z/', $entry['source_reference']) !== 1
            || ($entry['network'] ?? null) !== 'kairos'
            || ! isset($entry['recipient_address'])
            || ! is_string($entry['recipient_address'])
            || ! isset($payment->expires_at)) {
            return null;
        }

        try {
            return PaymentSnapshot::create(
                $this->networks->get('kairos'),
                $entry['recipient_address'],
                $payment->amount,
                $payment->expires_at,
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function snapshotIsEntirelyNull(object $payment): bool
    {
        foreach (self::SNAPSHOT_FIELDS as $field) {
            if (($payment->{$field} ?? null) !== null) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, array<int, array{id: int, reason: string}>> */
    private function emptyResult(): array
    {
        return [
            self::VALID => [],
            self::BACKFILLABLE => [],
            self::AMBIGUOUS => [],
            self::INVALID => [],
        ];
    }
}
