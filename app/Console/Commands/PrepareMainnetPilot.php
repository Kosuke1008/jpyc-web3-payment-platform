<?php

namespace App\Console\Commands;

use App\Blockchain\MainnetPilotPreflightChecker;
use Illuminate\Console\Command;

final class PrepareMainnetPilot extends Command
{
    protected $signature = 'payments:prepare-mainnet-pilot {paymentId}';

    protected $description = 'Read-only dry run for the single configured Mainnet pilot Payment';

    public function handle(MainnetPilotPreflightChecker $checker): int
    {
        $paymentId = $this->argument('paymentId');
        $report = $checker->check(is_string($paymentId) ? $paymentId : null);
        $this->line($report->ready ? 'READY_FOR_HUMAN_APPROVAL' : 'NOT_READY');
        $this->line(json_encode(
            $report->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ));

        return $report->ready ? self::SUCCESS : self::FAILURE;
    }
}
