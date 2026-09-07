<?php

namespace App\Console\Commands;

use App\Models\PaymentFeeDelegationAttempt;
use App\Services\Payments\FeeDelegationAttemptResolver;
use App\Services\Payments\FeeDelegationAttemptState;
use Illuminate\Console\Command;

class ResolveFeeDelegationAttempts extends Command
{
    protected $signature = 'payments:resolve-fee-delegation-attempts
        {--payment= : Resolve the attempt for one Payment ID}';

    protected $description = 'Resolve fee delegation attempts using read-only Kaia RPC lookups';

    public function handle(FeeDelegationAttemptResolver $resolver): int
    {
        $paymentId = $this->option('payment');
        $attempts = PaymentFeeDelegationAttempt::query()
            ->whereIn('state', [
                FeeDelegationAttemptState::SUBMITTING,
                FeeDelegationAttemptState::UNKNOWN_SUBMISSION,
                FeeDelegationAttemptState::SUBMITTED,
                FeeDelegationAttemptState::RECEIPT_OBSERVED,
            ])
            ->when($paymentId !== null, fn ($query) => $query->where('payment_id', $paymentId))
            ->orderBy('id')
            ->lazyById();

        $counts = [];
        $processed = 0;

        foreach ($attempts as $attempt) {
            $processed++;
            $result = $resolver->resolve($attempt);
            $counts[$result] = ($counts[$result] ?? 0) + 1;
        }

        if ($processed === 0) {
            $this->warn('No unresolved fee delegation attempts matched.');

            return self::FAILURE;
        }

        ksort($counts);
        $this->line(implode(' ', array_map(
            fn (string $name, int $count): string => "{$name}={$count}",
            array_keys($counts),
            array_values($counts)
        )));

        return isset($counts[FeeDelegationAttemptResolver::VERIFICATION_REJECTED])
            ? self::FAILURE
            : self::SUCCESS;
    }
}
