<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PaymentFeeDelegationAttempt;
use App\Models\Transaction;
use App\Models\User;
use App\Payments\MainnetPilotGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class RotateMainnetPilotSender extends Command
{
    protected $signature = 'mainnet:rotate-pilot-sender';

    protected $description = 'Rotate only the approved Mainnet staging pilot User sender before any sponsorship attempt';

    private const PAYMENT_EVIDENCE = [
        'tx_hash', 'paid_at', 'user_id', 'observed_chain_id',
        'confirmed_block_number', 'confirmed_block_hash', 'receipt_status',
        'payer_address', 'transfer_log_index', 'chain_confirmed_at',
        'verified_at', 'reconciliation_status', 'reconciliation_error_code',
        'reconciled_at',
    ];

    public function handle(MainnetPilotGuard $guard): int
    {
        try {
            $this->assertSafe($guard);
            if (! $this->input->isInteractive()) {
                throw new RuntimeException('interactive_input_required');
            }

            $currentInput = $this->ask('Current approved sender address');
            $newInput = $this->ask('New approved sender address');
            $newSender = $this->validateInputs($currentInput, $newInput);

            $result = $guard->transaction(function () use ($guard, $currentInput, $newInput, $newSender): array {
                $identities = $this->assertSafe($guard, lock: true);
                if ($this->validateInputs($currentInput, $newInput) !== $newSender) {
                    throw new RuntimeException('sender_input_changed');
                }

                $user = $identities['user'];
                $user->timestamps = false;
                $user->forceFill(['wallet_address' => $newSender])->save();

                return [
                    'user_id' => $user->id,
                    'old_sender_address' => $currentInput,
                    'new_sender_address' => $newSender,
                ];
            });
        } catch (Throwable $exception) {
            $diagnostic = $exception instanceof RuntimeException
                && in_array($exception->getMessage(), [
                    'interactive_input_required', 'sender_input_invalid',
                    'sender_input_mismatch', 'sender_identity_conflict',
                    'pilot_payment_evidence_present', 'pilot_attempt_present',
                    'fee_payer_gates_not_verified', 'sender_input_changed',
                ], true)
                    ? $exception->getMessage()
                    : 'environment_database_identity_or_transaction_guard_failed';
            $this->error('NOT_READY: sender rotation refused; diagnostic='.$diagnostic.'. No partial change was committed.');

            return self::FAILURE;
        }

        foreach ($result as $key => $value) {
            $this->line($key.'='.$value);
        }
        $this->warn('Update MAINNET_STAGING_APPROVED_SENDER_ADDRESSES manually in BOTH Laravel and Fee Payer configuration. Restart both services, then rerun readiness and pilot preflight before proceeding. No environment file was modified.');

        return self::SUCCESS;
    }

    /** @return array{user: User, merchant: string, sender: string, fee_payer: string} */
    private function assertSafe(MainnetPilotGuard $guard, bool $lock = false): array
    {
        $guard->assertDatabase();
        $guard->profile();
        $identities = $guard->identities(lock: $lock);
        $configuredSender = config('services.fee_delegation.mainnet_staging.approved_sender_addresses');
        if (! is_string($configuredSender)
            || $configuredSender !== $identities['sender']
            || $identities['user']->wallet_address !== $configuredSender) {
            throw new RuntimeException('sender_input_mismatch');
        }

        if (PaymentFeeDelegationAttempt::query()->exists()) {
            throw new RuntimeException('pilot_attempt_present');
        }
        $payments = Payment::query()->when($lock, fn ($query) => $query->lockForUpdate())->get();
        if ($payments->contains(function (Payment $payment): bool {
            if ($payment->status !== 'pending') {
                return true;
            }
            foreach (self::PAYMENT_EVIDENCE as $field) {
                if ($payment->getRawOriginal($field) !== null) {
                    return true;
                }
            }

            return false;
        }) || (Schema::hasTable('transactions') && Transaction::query()->exists())) {
            throw new RuntimeException('pilot_payment_evidence_present');
        }

        $this->assertFeePayerClosed();

        return $identities;
    }

    private function validateInputs(mixed $currentInput, mixed $newInput): string
    {
        $current = MainnetPilotGuard::address($currentInput);
        $new = MainnetPilotGuard::address($newInput);
        if ($current === null || $new === null) {
            throw new RuntimeException('sender_input_invalid');
        }
        $config = config('services.fee_delegation.mainnet_staging');
        if (! is_array($config)
            || $currentInput !== ($config['approved_sender_addresses'] ?? null)) {
            throw new RuntimeException('sender_input_mismatch');
        }
        $merchant = MainnetPilotGuard::address($config['merchant_address'] ?? null);
        $feePayer = MainnetPilotGuard::address($config['fee_payer_address'] ?? null);
        $kairosMerchant = MainnetPilotGuard::address($config['kairos_merchant_address'] ?? null);
        $kairosPayer = MainnetPilotGuard::address($config['kairos_fee_payer_address'] ?? null);
        if ($merchant === null || $feePayer === null || $kairosMerchant === null
            || $kairosPayer === null || in_array($new, [
                $current, $merchant, $feePayer, $kairosMerchant, $kairosPayer,
            ], true)) {
            throw new RuntimeException('sender_identity_conflict');
        }

        return $new;
    }

    private function assertFeePayerClosed(): void
    {
        $url = config('services.fee_delegation.mainnet_staging.fee_payer_health_url');
        $parts = is_string($url) ? parse_url($url) : false;
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'http'
            || ($parts['host'] ?? null) !== '127.0.0.1'
            || ($parts['path'] ?? null) !== '/health'
            || ! isset($parts['port']) || $parts['port'] < 1 || $parts['port'] > 65535
            || array_intersect_key($parts, array_flip(['user', 'pass', 'query', 'fragment'])) !== []) {
            throw new RuntimeException('fee_payer_gates_not_verified');
        }

        $response = Http::timeout(5)->withOptions(['allow_redirects' => false])
            ->withHeaders(['Cache-Control' => 'no-store'])->get($url);
        $health = in_array($response->status(), [200, 503], true)
            ? $response->json()
            : null;
        if (! is_array($health)
            || ! in_array($health['status'] ?? null, ['READ_ONLY_READY', 'READY'], true)
            || ($health['network'] ?? null) !== 'kaia-mainnet'
            || ($health['chain_id'] ?? null) !== 8217
            || ($health['execution'] ?? null) !== 'DISABLED'
            || ($health['signing'] ?? null) !== 'DISABLED'
            || ($health['broadcast'] ?? null) !== 'DISABLED'
            || ($health['kill_switch'] ?? null) !== 'ACTIVE') {
            throw new RuntimeException('fee_payer_gates_not_verified');
        }
    }
}
