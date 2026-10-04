<?php

namespace App\Payments;

use App\Blockchain\MainnetReadinessChecker;
use App\Blockchain\NetworkProfile;
use App\Models\Staff;
use App\Models\Wallet;
use App\Services\Payments\FeeDelegationEndpoint;
use App\Services\Payments\MainnetPaymentAuthorization;
use RuntimeException;

final class MainnetPaymentCreationPolicy
{
    public function __construct(
        private readonly MainnetPilotGuard $guard,
        private readonly MainnetPilotLimits $limits,
    ) {}

    public function assertAuthorized(
        NetworkProfile $profile,
        mixed $staff,
        Wallet $wallet,
        PaymentSnapshot $snapshot
    ): void {
        if ($profile->id !== 'kaia-mainnet') {
            return;
        }

        $this->assertConfigurationAuthorized($profile, $staff, $wallet);

        $limits = $this->limits->validate();
        $identities = $this->guard->identities();

        if ($snapshot->network !== 'kaia-mainnet'
            || $snapshot->chainId !== 8217
            || $snapshot->recipientAddress !== $identities['merchant']
            || bccomp($snapshot->displayAmount, $limits['max_payment_jpy'], 0) > 0) {
            throw new RuntimeException('mainnet_payment_creation_not_authorized');
        }
    }

    public function assertConfigurationAuthorized(
        NetworkProfile $profile,
        mixed $staff,
        Wallet $wallet
    ): void {
        if ($profile->id !== 'kaia-mainnet') {
            return;
        }

        $closed = config('blockchain.payments_mainnet_enabled') === false
            && config('blockchain.mainnet_fee_delegation_enabled') === false
            && config('blockchain.mainnet_broadcast_enabled') === false
            && config('services.fee_delegation.self_hosted_mainnet.enabled') === false
            && config('services.fee_delegation.self_hosted_mainnet.kill_switch') === true;
        $live = config('blockchain.payments_mainnet_enabled') === true
            && config('blockchain.mainnet_fee_delegation_enabled') === true
            && config('blockchain.mainnet_broadcast_enabled') === true
            && config('services.fee_delegation.enabled') === true
            && config('services.fee_delegation.self_hosted_mainnet.enabled') === true
            && config('services.fee_delegation.self_hosted_mainnet.kill_switch') === false;

        if (! $staff instanceof Staff
            || config('blockchain.mainnet_activation_release_capable') !== true
            || config('services.fee_delegation.mode') !== 'self-hosted'
            || (! $closed && ! $live)
            || ! FeeDelegationEndpoint::isAllowed(
                config('services.fee_delegation.url'),
                config('services.fee_delegation.api_key')
            )
            || $profile->chainId !== 8217
            || $profile->jpycContract !== MainnetReadinessChecker::JPYC_CONTRACT
            || $profile->jpycSymbol !== 'JPYC'
            || $profile->jpycDecimals !== 18
            || $profile->testnet
            || ! $profile->paymentExecutionEnabled
            || ! $profile->feeDelegationExecutionEnabled) {
            throw new RuntimeException('mainnet_payment_creation_not_authorized');
        }

        $this->guard->assertDatabase();
        $identities = $this->guard->identities();
        $this->limits->validate();
        MainnetPaymentAuthorization::keyId();

        if ($staff->id !== $identities['staff']->id
            || $staff->store_id !== $identities['store']->id
            || $wallet->store_id !== $identities['store']->id
            || $wallet->network !== 'kaia-mainnet'
            || strtolower($wallet->address) !== $identities['merchant']
        ) {
            throw new RuntimeException('mainnet_payment_creation_not_authorized');
        }
    }
}
