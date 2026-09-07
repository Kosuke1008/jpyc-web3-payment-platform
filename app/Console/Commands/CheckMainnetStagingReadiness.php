<?php

namespace App\Console\Commands;

use App\Blockchain\MainnetStagingReadinessChecker;
use Illuminate\Console\Command;

final class CheckMainnetStagingReadiness extends Command
{
    protected $signature = 'blockchain:mainnet-staging-readiness';

    protected $description = 'Aggregate read-only Kaia Mainnet staging readiness';

    public function handle(MainnetStagingReadinessChecker $checker): int
    {
        $report = $checker->check();
        $this->line(json_encode(
            $report->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ));

        return $report->ready ? self::SUCCESS : self::FAILURE;
    }
}
