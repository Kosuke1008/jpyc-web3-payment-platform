<?php

namespace App\Console\Commands;

use App\Blockchain\MainnetPilotPreflightChecker;
use Illuminate\Console\Command;

final class CheckMainnetPilotPreflight extends Command
{
    protected $signature = 'blockchain:mainnet-pilot-preflight';

    protected $description = 'Validate the disabled one-Payment Mainnet pilot preflight';

    public function handle(MainnetPilotPreflightChecker $checker): int
    {
        $report = $checker->check();
        $this->line(json_encode(
            $report->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ));

        return $report->ready ? self::SUCCESS : self::FAILURE;
    }
}
