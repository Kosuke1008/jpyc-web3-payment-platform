<?php

namespace App\Console\Commands;

use App\Blockchain\MainnetPilotGateStateChecker;
use Illuminate\Console\Command;

final class MainnetPilotGateStatus extends Command
{
    protected $signature = 'mainnet:pilot-gate-status';

    protected $aliases = ['mainnet:pilot-shutdown-check'];

    protected $description = 'Read-only verification that local and Fee Payer Mainnet gates are closed';

    public function handle(MainnetPilotGateStateChecker $checker): int
    {
        $result = $checker->check(
            shutdownInvocation: $this->input->getFirstArgument() === 'mainnet:pilot-shutdown-check'
        );

        $this->line('overall='.$result['state']);
        foreach ($result['context'] as $key => $value) {
            $this->line($key.'='.$value);
        }

        return in_array($result['state'], ['SAFE_DEPLOYED', 'LIVE_ENABLED', 'SHUTDOWN_VERIFIED'], true)
            ? self::SUCCESS : self::FAILURE;
    }
}
