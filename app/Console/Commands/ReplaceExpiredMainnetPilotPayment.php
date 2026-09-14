<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Payments\MainnetPilotGuard;
use App\Payments\MainnetPilotPaymentHistory;
use App\Payments\PaymentSnapshot;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class ReplaceExpiredMainnetPilotPayment extends Command
{
    protected $signature = 'mainnet:replace-expired-pilot-payment';

    protected $description = 'Preserve expired unused Mainnet pilot history and create one replacement Payment';

    public function handle(
        MainnetPilotGuard $guard,
        MainnetPilotPaymentHistory $history
    ): int {
        try {
            $result = $guard->transaction(function () use ($guard, $history): array {
                $profile = $guard->profile();
                $identities = $guard->identities(lock: true);
                $replacement = $history->assertReplaceable(
                    Payment::query()->orderBy('id')->lockForUpdate()->get(),
                    config('services.fee_delegation.mainnet_staging.pilot_payment_id'),
                    $identities,
                    $profile
                );
                $old = $replacement['latest'];
                $snapshot = PaymentSnapshot::fromRecord($old);

                $ttl = MainnetPilotGuard::positiveId(
                    config('blockchain.payment_expiration_seconds')
                );
                if ($ttl === null || $ttl < 60 || $ttl > 86400) {
                    throw new RuntimeException('pilot_expiration_invalid');
                }

                $newSnapshot = PaymentSnapshot::create(
                    $profile,
                    $identities['merchant'],
                    '1',
                    now()->addSeconds($ttl),
                    1
                );
                $new = Payment::query()->create(array_merge([
                    'store_id' => $identities['store']->id,
                    'staff_id' => $identities['staff']->id,
                    'amount' => 1,
                    'status' => 'pending',
                ], $newSnapshot->databaseAttributes()));

                return [
                    'old_payment_id' => $old->id,
                    'new_payment_id' => $new->id,
                    'history_count' => $replacement['history_count'],
                    'old_expires_at' => $snapshot->expiresAt->toIso8601String(),
                    'new_expires_at' => $newSnapshot->expiresAt->toIso8601String(),
                ];
            });
        } catch (Throwable $exception) {
            $diagnostic = $exception instanceof RuntimeException
                && in_array($exception->getMessage(), [
                    'expired_pilot_history_required',
                    'expired_pilot_history_invalid',
                    'latest_pilot_payment_id_required',
                    'pilot_payment_id_does_not_reference_latest_expired_payment',
                    'fee_delegation_attempt_exists',
                    'legacy_transaction_record_exists',
                    'pilot_expiration_invalid',
                ], true)
                    ? $exception->getMessage()
                    : 'environment_database_identity_or_transaction_guard_failed';
            $this->error('NOT_READY: replacement refused; diagnostic='.$diagnostic.'. No Payment was changed or created.');

            return self::FAILURE;
        }

        foreach ($result as $key => $value) {
            $this->line($key.'='.$value);
        }
        $this->line('MAINNET_PILOT_PAYMENT_ID='.$result['new_payment_id']);
        $this->warn('Update MAINNET_PILOT_PAYMENT_ID manually in both approved service configurations, clear configuration cache, restart read-only services, and rerun preflight. No environment file was modified.');

        return self::SUCCESS;
    }
}
