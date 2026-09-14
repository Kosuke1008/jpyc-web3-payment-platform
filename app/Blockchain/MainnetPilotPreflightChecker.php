<?php

namespace App\Blockchain;

use App\Models\Payment;
use App\Models\PaymentFeeDelegationAttempt;
use App\Payments\MainnetPilotGuard;
use App\Payments\MainnetPilotLimits;
use App\Payments\MainnetPilotPaymentHistory;
use App\Payments\PaymentSnapshot;
use Throwable;

final class MainnetPilotPreflightChecker
{
    public function __construct(
        private readonly MainnetStagingReadinessChecker $staging,
        private readonly MainnetPilotGuard $guard,
        private readonly MainnetPilotLimits $limits,
        private readonly MainnetPilotRpc $rpc,
        private readonly MainnetPilotPaymentHistory $history,
    ) {}

    public function check(int|string|null $requestedPaymentId = null): MainnetPilotPreflightReport
    {
        $checks = [];
        $context = [
            'activation_release_capable' => config('blockchain.mainnet_activation_release_capable') === true
                ? 'YES' : 'NO',
        ];
        $this->record($checks, 'broadcast_disabled', config('blockchain.mainnet_broadcast_enabled') === false, 'broadcast_gate_not_disabled');
        $this->record($checks, 'kill_switch', config('services.fee_delegation.self_hosted_mainnet.kill_switch') === true, 'kill_switch_not_active');
        try {
            $this->guard->assertDatabase();
            $this->record($checks, 'environment_database', true, '');
        } catch (Throwable) {
            $this->record($checks, 'environment_database', false, 'mainnet_staging_environment_or_database_mismatch');

            return new MainnetPilotPreflightReport(false, $checks, $context);
        }
        try {
            $profile = $this->guard->profile();
        } catch (Throwable) {
            $profile = null;
        }
        $this->record($checks, 'disabled_gates', $profile !== null, 'mainnet_profile_or_execution_gate_invalid');
        $staging = $this->staging->check(readOnly: true);
        $this->record($checks, 'remote_disabled_gates', $staging->checks['fee_payer_service']['ready'] ?? false, 'fee_payer_disabled_state_not_verified');
        $this->record($checks, 'staging_readiness', $staging->ready, 'staging_not_read_only_ready');
        $this->record($checks, 'database_and_cache',
            ($staging->checks['database']['ready'] ?? false)
                && ($staging->checks['migration_state']['ready'] ?? false)
                && ($staging->checks['stage2_hardening']['diagnostic'] ?? null) === 'applied'
                && ($staging->checks['cache_lock']['ready'] ?? false), 'database_or_cache_not_ready');
        $this->record($checks, 'rpc', ($staging->checks['primary_rpc']['ready'] ?? false)
            && ($staging->checks['secondary_rpc']['ready'] ?? false)
            && ($staging->checks['jpyc_contract']['ready'] ?? false), 'rpc_not_ready');
        $this->record($checks, 'signer', $staging->checks['signer_state']['ready'] ?? false, 'signer_not_ready');

        $configuredId = MainnetPilotGuard::positiveId(config('services.fee_delegation.mainnet_staging.pilot_payment_id'));
        $requestedId = $requestedPaymentId === null ? $configuredId : MainnetPilotGuard::positiveId($requestedPaymentId);
        $idReady = $configuredId !== null && $requestedId === $configuredId;
        $this->record($checks, 'pilot_payment_id', $idReady, 'pilot_payment_id_invalid');
        try {
            $identities = $this->guard->identities();
            $context += [
                'store_id' => $identities['store']->id, 'approved_user_id' => $identities['user']->id,
                'merchant_address' => $identities['merchant'], 'sender_address' => $identities['sender'],
                'fee_payer_address' => $identities['fee_payer'],
            ];
        } catch (Throwable) {
            $identities = null;
        }
        $this->record($checks, 'pilot_identity', $identities !== null, 'pilot_identity_or_database_relationship_mismatch');
        try {
            $limits = $this->limits->validate();
            $genericMaxGas = MainnetPilotLimits::integer(config('services.fee_delegation.max_gas'));
            $limitsReady = bccomp($limits['max_gas'], $genericMaxGas, 0) <= 0;
        } catch (Throwable) {
            $limits = null;
            $limitsReady = false;
        }
        $this->record($checks, 'pilot_limits', $limitsReady, 'pilot_limits_invalid_or_exceed_generic_gas_cap');
        $expectedPolicy = $limits === null || $identities === null || $configuredId === null ? null : [
            'ready' => true, 'max_payment_jpyc' => '1', 'max_gas' => $limits['max_gas'],
            'max_gas_price_wei' => $limits['max_gas_price_wei'], 'rate_window_seconds' => $limits['rate_window_seconds'],
            'max_attempts_per_user' => '1', 'max_attempts_per_store' => '1', 'max_attempts_per_sender' => '1',
            'max_attempts_global' => '1', 'daily_transaction_limit' => '1',
            'daily_kaia_budget_wei' => $limits['daily_kaia_budget_wei'], 'minimum_reserve_wei' => $limits['minimum_reserve_wei'],
            'maximum_balance_wei' => $limits['maximum_balance_wei'], 'merchant_address' => $identities['merchant'],
            'sender_address' => $identities['sender'], 'pilot_payment_id' => (string) $configuredId,
        ];
        $remotePolicy = $staging->publicContext['fee_payer_pilot_policy'] ?? [];
        $policyMatches = $expectedPolicy !== null;
        foreach ($expectedPolicy ?? [] as $key => $value) {
            $policyMatches = $policyMatches && ($remotePolicy[$key] ?? null) === $value;
        }
        $this->record($checks, 'fee_payer_policy', $policyMatches, 'fee_payer_pilot_policy_not_ready_or_mismatched');

        $payment = null;
        $paymentReady = false;
        try {
            $payment = $idReady ? Payment::find($configuredId) : null;
            if ($payment !== null && $identities !== null && $profile !== null) {
                $snapshot = PaymentSnapshot::fromRecord($payment);
                $paymentReady = $this->history->supportsCurrent($payment, $identities, $profile)
                    && $payment->status === 'pending' && $payment->tx_hash === null
                    && $payment->store_id === $identities['store']->id
                    && $payment->staff_id === $identities['staff']->id
                    && $payment->user_id === null
                    && (string) $payment->amount === '1'
                    && $payment->created_at !== null && $payment->created_at->lte(now())
                    && $snapshot->expiresAt->gt(now()) && $snapshot->expiresAt->gt($payment->created_at)
                    && $snapshot->network === 'kaia-mainnet' && $snapshot->chainId === 8217
                    && $snapshot->networkProfileVersion === $profile->version
                    && $snapshot->tokenContract === MainnetReadinessChecker::JPYC_CONTRACT
                    && $snapshot->tokenSymbol === 'JPYC' && $snapshot->tokenDecimals === 18
                    && $snapshot->displayAmount === '1' && $snapshot->atomicAmount === '1000000000000000000'
                    && $snapshot->recipientAddress === $identities['merchant'];
                $context['payment_id'] = $payment->id;
                $context['expires_at'] = $snapshot->expiresAt->toIso8601String();
            }
        } catch (Throwable) {
            $paymentReady = false;
        }
        $this->record($checks, 'pilot_payment', $paymentReady, $idReady ? 'pilot_payment_snapshot_or_relationship_invalid' : 'not_checked_without_valid_payment_id');
        try {
            $noAttempts = $payment !== null && ! PaymentFeeDelegationAttempt::query()->exists();
            $attemptDiagnostic = $payment === null ? 'not_checked_without_valid_payment' : 'pilot_attempt_already_exists';
        } catch (Throwable) {
            $noAttempts = false;
            $attemptDiagnostic = 'pilot_attempt_query_failed';
        }
        $this->record($checks, 'no_existing_attempt', $noAttempts, $attemptDiagnostic);

        $balanceReady = false;
        $gasReady = false;
        if ($limits !== null && $identities !== null && $profile !== null) {
            $context += [
                'maximum_gas_spend_wei' => $limits['max_transaction_fee_wei'],
                'minimum_reserve_after_transaction_wei' => $limits['minimum_reserve_wei'],
                'recommended_funding_wei' => $limits['minimum_required_balance_wei'],
                'recommended_funding_kaia' => MainnetPilotLimits::weiToKaia($limits['minimum_required_balance_wei']),
            ];
            try {
                $balance = $this->rpc->balance($identities['fee_payer']);
                $context += $balance;
                $balanceReady = bccomp($balance['balance_wei'], $limits['minimum_required_balance_wei'], 0) >= 0
                    && bccomp($balance['balance_wei'], $limits['maximum_balance_wei'], 0) <= 0;
                $gas = $this->rpc->gas($identities['sender'], $identities['merchant']);
                $context += $gas;
                $gasReady = bccomp($gas['observed_required_gas'], $limits['max_gas'], 0) <= 0
                    && bccomp($gas['observed_gas_price_wei'], $limits['max_gas_price_wei'], 0) <= 0;
            } catch (Throwable) {
                // Never expose provider URLs or error bodies.
            }
        }
        $this->record($checks, 'fee_payer_balance', $balanceReady, 'fee_payer_balance_unreadable_or_outside_pilot_range');
        $this->record($checks, 'gas_observation', $gasReady, 'gas_observation_unavailable_or_above_caps');

        return new MainnetPilotPreflightReport(! in_array(false, array_column($checks, 'ready'), true), $checks, $context);
    }

    private function record(array &$checks, string $name, bool $ready, string $diagnostic): void
    {
        $checks[$name] = ['ready' => $ready, 'diagnostic' => $ready ? 'none' : $diagnostic];
    }
}
