<?php

namespace App\Blockchain;

use App\Payments\MainnetPilotGuard;
use App\Payments\MainnetPilotLimits;
use RuntimeException;

final class MainnetPilotFundingPlanner
{
    public function __construct(
        private readonly MainnetPilotGuard $guard,
        private readonly MainnetPilotLimits $limits,
        private readonly MainnetStagingReadinessChecker $staging,
        private readonly MainnetPilotRpc $rpc,
    ) {}

    public function plan(): array
    {
        $this->guard->assertDatabase();
        $this->guard->profile();
        $identities = $this->guard->identities();
        $limits = $this->limits->validate();
        $staging = $this->staging->check(readOnly: true);
        if (! $staging->ready || ($staging->publicContext['fee_payer_address'] ?? null) !== $identities['fee_payer']) {
            throw new RuntimeException('pilot_staging_or_fee_payer_identity_not_ready');
        }
        $observation = $this->rpc->balance($identities['fee_payer']);
        $balance = $observation['balance_wei'];
        if (bccomp($balance, $limits['maximum_balance_wei'], 0) > 0) {
            throw new RuntimeException('pilot_balance_above_maximum');
        }
        $difference = bcsub($limits['minimum_required_balance_wei'], $balance, 0);
        $topUp = bccomp($difference, '0', 0) > 0 ? $difference : '0';

        return array_merge([
            'max_gas' => $limits['max_gas'], 'max_gas_price_wei' => $limits['max_gas_price_wei'],
            'max_transaction_fee_wei' => $limits['max_transaction_fee_wei'],
            'max_transaction_fee_kaia' => MainnetPilotLimits::weiToKaia($limits['max_transaction_fee_wei']),
            'minimum_reserve_kaia' => MainnetPilotLimits::weiToKaia($limits['minimum_reserve_wei']),
            'minimum_required_balance_kaia' => MainnetPilotLimits::weiToKaia($limits['minimum_required_balance_wei']),
            'maximum_allowed_balance_kaia' => MainnetPilotLimits::weiToKaia($limits['maximum_balance_wei']),
            'current_balance_kaia' => MainnetPilotLimits::weiToKaia($balance),
            'recommended_top_up_kaia' => MainnetPilotLimits::weiToKaia($topUp),
            'fee_payer_address' => $identities['fee_payer'],
        ], $observation);
    }
}
