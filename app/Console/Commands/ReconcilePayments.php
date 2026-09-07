<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\Payments\PaymentReconciliationService;
use Illuminate\Console\Command;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {payment? : A confirmed Payment ID}';

    protected $description = 'Recheck stored confirmation evidence without broadcasting transactions';

    public function handle(PaymentReconciliationService $reconciliation): int
    {
        $paymentId = $this->argument('payment');
        $payments = Payment::query()
            ->where('status', 'confirmed')
            ->when($paymentId !== null, fn ($query) => $query->whereKey($paymentId))
            ->orderBy('id')
            ->lazyById();

        $counts = [
            PaymentReconciliationService::VERIFIED => 0,
            PaymentReconciliationService::ANOMALY => 0,
            PaymentReconciliationService::TRANSIENT_FAILURE => 0,
        ];

        $processed = 0;

        foreach ($payments as $payment) {
            $processed++;
            $counts[$reconciliation->reconcile($payment)]++;
        }

        if ($processed === 0) {
            $this->warn('No confirmed Payments matched.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'verified=%d anomaly=%d transient_failure=%d',
            $counts[PaymentReconciliationService::VERIFIED],
            $counts[PaymentReconciliationService::ANOMALY],
            $counts[PaymentReconciliationService::TRANSIENT_FAILURE]
        ));

        return $counts[PaymentReconciliationService::ANOMALY] === 0
            ? self::SUCCESS
            : self::FAILURE;
    }
}
