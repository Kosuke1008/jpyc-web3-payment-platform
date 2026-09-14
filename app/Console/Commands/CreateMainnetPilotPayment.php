<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PaymentFeeDelegationAttempt;
use App\Payments\MainnetPilotGuard;
use App\Payments\PaymentSnapshot;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class CreateMainnetPilotPayment extends Command
{
    protected $signature = 'mainnet:create-pilot-payment';

    protected $description = 'Create exactly one 1 JPYC staging Payment while all execution gates stay closed';

    public function handle(MainnetPilotGuard $guard): int
    {
        try {
            $result = $guard->transaction(function () use ($guard): array {
                $profile = $guard->profile();
                $identities = $guard->identities(lock: true);
                if (Payment::query()->exists() || PaymentFeeDelegationAttempt::query()->exists()
                    || ! in_array(config('services.fee_delegation.mainnet_staging.pilot_payment_id'), [null, ''], true)) {
                    throw new RuntimeException('pilot_payment_or_attempt_already_present');
                }
                $ttl = MainnetPilotGuard::positiveId(config('blockchain.payment_expiration_seconds'));
                if ($ttl === null || $ttl < 60 || $ttl > 86400) {
                    throw new RuntimeException('pilot_expiration_invalid');
                }
                $snapshot = PaymentSnapshot::create($profile, $identities['merchant'], '1', now()->addSeconds($ttl), 1);
                $payment = Payment::query()->create(array_merge([
                    'store_id' => $identities['store']->id,
                    'staff_id' => $identities['staff']->id,
                    'amount' => 1,
                    'status' => 'pending',
                ], $snapshot->databaseAttributes()));

                return [
                    'payment_id' => $payment->id, 'store_id' => $payment->store_id,
                    'user_id' => $identities['user']->id, 'network' => $snapshot->network,
                    'network_profile_version' => $snapshot->networkProfileVersion,
                    'chain_id' => $snapshot->chainId, 'recipient' => $snapshot->recipientAddress,
                    'display_amount' => $snapshot->displayAmount, 'atomic_amount' => $snapshot->atomicAmount,
                    'expires_at' => $snapshot->expiresAt->toIso8601String(),
                ];
            });
        } catch (Throwable) {
            $this->error('NOT_READY: pilot creation refused; verify dedicated DB, disabled gates, exact identities, empty payments/attempts and expiry configuration. No partial records are committed.');

            return self::FAILURE;
        }
        foreach ($result as $key => $value) {
            $this->line($key.'='.$value);
        }
        $this->line('MAINNET_PILOT_PAYMENT_ID='.$result['payment_id']);

        return self::SUCCESS;
    }
}
