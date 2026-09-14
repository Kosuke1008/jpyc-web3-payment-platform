<?php

namespace App\Console\Commands;

use App\Blockchain\MainnetPilotFundingPlanner;
use Illuminate\Console\Command;
use Throwable;

final class MainnetPilotFundingPlan extends Command
{
    protected $signature = 'mainnet:pilot-funding-plan';

    protected $description = 'Read-only exact funding plan using both RPCs; never transfers funds';

    public function handle(MainnetPilotFundingPlanner $planner): int
    {
        try {
            $plan = $planner->plan();
        } catch (Throwable) {
            $this->error('NOT_READY: verify staging readiness, exact identities, pilot limits, matching RPC chain/balance and maximum balance.');

            return self::FAILURE;
        }
        foreach ($plan as $key => $value) {
            $this->line($key.'='.$value);
        }

        return self::SUCCESS;
    }
}
