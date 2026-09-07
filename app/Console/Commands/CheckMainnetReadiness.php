<?php

namespace App\Console\Commands;

use App\Blockchain\MainnetReadinessChecker;
use Illuminate\Console\Command;

final class CheckMainnetReadiness extends Command
{
    protected $signature = 'blockchain:mainnet-readiness';

    protected $description = 'Validate Kaia Mainnet read-only configuration and RPC providers';

    public function handle(MainnetReadinessChecker $checker): int
    {
        $report = $checker->check();

        foreach (['primary', 'secondary'] as $name) {
            $provider = $report->{$name};
            $this->line(sprintf(
                '%s=%s diagnostic=%s chain_id=%s latest_block=%s',
                $name,
                $provider['ready'] ? 'ready' : 'failed',
                $provider['diagnostic_code'] ?? 'none',
                $provider['chain_id'] ?? 'unknown',
                $provider['latest_block'] ?? 'unknown',
            ));
        }

        $this->line('mainnet_execution=disabled');
        $this->line('readiness='.($report->ready ? 'ready' : 'failed'));

        if (! $report->ready) {
            $this->error('diagnostic='.($report->diagnosticCode ?? 'unknown'));
        }

        return $report->ready ? self::SUCCESS : self::FAILURE;
    }
}
