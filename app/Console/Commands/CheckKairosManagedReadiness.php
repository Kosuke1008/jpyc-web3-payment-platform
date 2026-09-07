<?php

namespace App\Console\Commands;

use App\Blockchain\KairosManagedReadinessChecker;
use Illuminate\Console\Command;

final class CheckKairosManagedReadiness extends Command
{
    protected $signature = 'blockchain:kairos-managed-readiness';

    protected $description = 'Validate the controlled Kairos managed fee-delegation test without broadcasting';

    public function handle(KairosManagedReadinessChecker $checker): int
    {
        $report = $checker->check();

        foreach ($report->checks as $name => $check) {
            $this->line(sprintf(
                '%s=%s diagnostic=%s',
                $name,
                $check['ready'] ? 'ready' : 'failed',
                $check['diagnostic']
            ));
        }

        $this->line('provider_call=not_performed');
        $this->line('mainnet_execution=disabled');
        $this->line('readiness='.($report->ready ? 'ready' : 'failed'));

        return $report->ready ? self::SUCCESS : self::FAILURE;
    }
}
