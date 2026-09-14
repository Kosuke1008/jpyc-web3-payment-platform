<?php

namespace App\Console\Commands;

use App\Blockchain\MainnetPilotRpc;
use App\Blockchain\MainnetReadinessChecker;
use App\Blockchain\NetworkProfileRegistry;
use App\Payments\MainnetPilotGuard;
use Illuminate\Console\Command;
use Throwable;

final class ObserveMainnetPilotGas extends Command
{
    protected $signature = 'mainnet:pilot-gas-observation';

    protected $description = 'Read-only unsigned 1 JPYC gas estimates and current prices on both RPCs';

    public function handle(
        MainnetPilotGuard $guard,
        MainnetPilotRpc $rpc,
        NetworkProfileRegistry $networks
    ): int {
        try {
            $guard->assertDatabase();
            $profile = $networks->active();
            if ($profile->id !== 'kaia-mainnet' || $profile->chainId !== 8217
                || $profile->jpycContract !== MainnetReadinessChecker::JPYC_CONTRACT
                || $profile->jpycDecimals !== 18) {
                throw new \RuntimeException('mainnet_profile_invalid');
            }
            $identities = $guard->identities();
            $observation = $rpc->gas($identities['sender'], $identities['merchant']);
        } catch (Throwable) {
            $this->error('NOT_READY: gas observation unavailable; verify identity, chain, RPC and sender JPYC balance. Do not guess gas limits.');

            return self::FAILURE;
        }
        foreach ($observation as $key => $value) {
            $this->line($key.'='.$value);
        }
        $this->line('Observation only: approve explicit gas/price caps, then repeat immediately before the pilot.');

        return self::SUCCESS;
    }
}
