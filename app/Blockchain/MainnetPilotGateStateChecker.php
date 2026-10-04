<?php

namespace App\Blockchain;

use App\Models\Payment;
use App\Models\PaymentFeeDelegationAttempt;
use App\Payments\MainnetPilotGuard;
use App\Payments\MainnetPilotLimits;
use App\Payments\PaymentSnapshot;
use App\Services\Payments\MainnetPaymentAuthorization;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Read-only source of truth for the local and remote pilot gate state. */
final class MainnetPilotGateStateChecker
{
    public function __construct(
        private readonly MainnetPilotGuard $guard,
        private readonly MainnetStagingReadinessChecker $staging,
        private readonly NetworkProfileRegistry $networks,
        private readonly MainnetPilotLimits $limits,
        private readonly MainnetPilotRpc $rpc,
    ) {}

    /** @return array{state: string, live: bool, context: array<string, string>} */
    public function check(
        bool $shutdownInvocation = false,
        int|string|null $requestedPaymentId = null
    ): array {
        try {
            $this->guard->assertDatabase();
            $this->staging->check(readOnly: true);
            $url = config('services.fee_delegation.mainnet_staging.fee_payer_health_url');
            $parts = is_string($url) ? parse_url($url) : false;
            $safeUrl = is_array($parts)
                && ($parts['scheme'] ?? null) === 'http'
                && ($parts['host'] ?? null) === '127.0.0.1'
                && ($parts['path'] ?? null) === '/health'
                && ! isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment']);
            $response = $safeUrl
                ? Http::timeout(5)->withOptions(['allow_redirects' => false])->get($url)
                : null;
            $remoteLiveHttp = $response?->status() === 200;
            $remote = $response !== null && in_array($response->status(), [200, 503], true)
                ? $response->json() : null;
        } catch (Throwable) {
            $remote = null;
            $remoteLiveHttp = false;
        }

        $capable = config('blockchain.mainnet_activation_release_capable') === true
            && is_array($remote) && ($remote['activation_release_capable'] ?? null) === true;
        $payments = config('blockchain.payments_mainnet_enabled') === true;
        $selfHosted = config('services.fee_delegation.self_hosted_mainnet.enabled') === true;
        $delegation = config('blockchain.mainnet_fee_delegation_enabled') === true
            && config('services.fee_delegation.enabled') === true;
        $broadcast = config('blockchain.mainnet_broadcast_enabled') === true;
        $kill = config('services.fee_delegation.self_hosted_mainnet.kill_switch') === true;
        $remoteExecution = is_array($remote) && ($remote['execution'] ?? null) === 'ENABLED';
        $remoteSigning = is_array($remote) && ($remote['signing'] ?? null) === 'ENABLED';
        $remoteBroadcast = is_array($remote) && ($remote['broadcast'] ?? null) === 'ENABLED';
        $remoteKill = is_array($remote) && ($remote['kill_switch'] ?? null) === 'ACTIVE';
        $remoteVerified = is_array($remote)
            && in_array($remote['status'] ?? null, ['READ_ONLY_READY', 'READY'], true)
            && ($remote['network'] ?? null) === 'kaia-mainnet'
            && ($remote['chain_id'] ?? null) === 8217
            && in_array($remote['execution'] ?? null, ['ENABLED', 'DISABLED'], true)
            && in_array($remote['signing'] ?? null, ['ENABLED', 'DISABLED'], true)
            && in_array($remote['broadcast'] ?? null, ['ENABLED', 'DISABLED'], true)
            && in_array($remote['kill_switch'] ?? null, ['ACTIVE', 'INACTIVE'], true);
        $pilotId = MainnetPilotGuard::positiveId(
            $requestedPaymentId ?? config(
                'services.fee_delegation.mainnet_staging.pilot_payment_id'
            )
        );

        try {
            $attemptCount = $pilotId === null
                ? -1
                : PaymentFeeDelegationAttempt::query()
                    ->where('payment_id', $pilotId)
                    ->count();
        } catch (Throwable) {
            $attemptCount = -1;
        }

        $policy = is_array($remote) && is_array($remote['pilot_policy'] ?? null)
            ? $remote['pilot_policy'] : [];
        $balance = is_array($remote) ? ($remote['balance_wei'] ?? null) : null;
        $minimum = $policy['minimum_reserve_wei'] ?? null;
        $maximum = $policy['maximum_balance_wei'] ?? null;
        $maxGas = $policy['max_gas'] ?? null;
        $maxGasPrice = $policy['max_gas_price_wei'] ?? null;
        $maxPayment = $policy['max_payment_jpyc'] ?? null;
        $authorizationKeyId = $policy['authorization_key_id'] ?? null;
        $authorizationMode = $policy['authorization_mode'] ?? null;
        $pilotReady = $pilotId !== null && ($policy['ready'] ?? null) === true
            && is_string($balance) && preg_match('/\A[0-9]+\z/', $balance) === 1
            && is_string($minimum) && preg_match('/\A[0-9]+\z/', $minimum) === 1
            && is_string($maximum) && preg_match('/\A[0-9]+\z/', $maximum) === 1
            && is_string($maxGas) && preg_match('/\A[1-9][0-9]*\z/', $maxGas) === 1
            && is_string($maxGasPrice) && preg_match('/\A[1-9][0-9]*\z/', $maxGasPrice) === 1
            && is_string($maxPayment) && preg_match('/\A[1-9][0-9]*\z/', $maxPayment) === 1
            && bccomp($balance, bcadd($minimum, bcmul($maxGas, $maxGasPrice, 0), 0), 0) >= 0
            && bccomp($balance, $maximum, 0) <= 0
            && $attemptCount === 0;
        try {
            $payment = $pilotId === null ? null : Payment::find($pilotId);
            $snapshot = $payment === null ? null : PaymentSnapshot::fromRecord($payment);
            $profile = $this->networks->active();
            $identities = $this->guard->identities();
            $limits = $this->limits->validate();
            $pilotReady = $pilotReady && $payment?->status === 'pending'
                && $maxPayment === $limits['max_payment_jpy']
                && $maxGas === $limits['max_gas']
                && $maxGasPrice === $limits['max_gas_price_wei']
                && $minimum === $limits['minimum_reserve_wei']
                && $maximum === $limits['maximum_balance_wei']
                && ($policy['rate_window_seconds'] ?? null) === $limits['rate_window_seconds']
                && ($policy['daily_kaia_budget_wei'] ?? null) === $limits['daily_kaia_budget_wei']
                && $authorizationKeyId === MainnetPaymentAuthorization::keyId()
                && $authorizationMode === 'hmac-sha256-v1'
                && ($policy['max_attempts_per_user'] ?? null) === $limits['max_attempts_per_user']
                && ($policy['max_attempts_per_store'] ?? null) === $limits['max_attempts_per_store']
                && ($policy['max_attempts_per_sender'] ?? null) === $limits['max_attempts_per_sender']
                && ($policy['max_attempts_global'] ?? null) === $limits['max_attempts_global']
                && ($policy['daily_transaction_limit'] ?? null) === $limits['daily_transaction_limit']
                && bccomp($snapshot?->displayAmount ?? '0', $maxPayment, 0) <= 0
                && ($policy['merchant_address'] ?? null) === $identities['merchant']
                && ($policy['sender_address'] ?? null) === $identities['sender']
                && ($remote['fee_payer_address'] ?? null) === $identities['fee_payer']
                && $snapshot?->expiresAt->gt(now()) === true
                && $payment->store_id === $identities['store']->id
                && $payment->staff_id === $identities['staff']->id
                && $payment->user_id === null
                && $payment->mainnet_authorized_at !== null
                && $payment->tx_hash === null
                && $snapshot->displayAmount === (string) $payment->amount
                && $snapshot->network === 'kaia-mainnet'
                && $snapshot->networkProfileVersion === $profile->version
                && $snapshot->chainId === 8217
                && $snapshot->tokenContract === MainnetReadinessChecker::JPYC_CONTRACT
                && $snapshot->tokenSymbol === 'JPYC'
                && $snapshot->tokenDecimals === 18
                && $snapshot->recipientAddress === $identities['merchant']
                && $snapshot->atomicAmount === bcmul($snapshot->displayAmount, bcpow('10', '18', 0), 0);
        } catch (Throwable) {
            $pilotReady = false;
        }
        if ($pilotReady) {
            try {
                $tokenBalance = $this->rpc->tokenBalance($identities['sender']);
                $pilotReady = bccomp($tokenBalance['sender_jpyc_atomic'], $snapshot->atomicAmount, 0) >= 0;
                $gas = $pilotReady
                    ? $this->rpc->gas($identities['sender'], $identities['merchant'], $snapshot->atomicAmount)
                    : null;
                $pilotReady = $gas !== null
                    && bccomp($gas['observed_required_gas'], $maxGas, 0) <= 0
                    && bccomp($gas['observed_gas_price_wei'], $maxGasPrice, 0) <= 0;
            } catch (Throwable) {
                $pilotReady = false;
            }
        }
        $closed = ! $payments && ! $selfHosted && ! $delegation && ! $broadcast
            && $remoteVerified && ! $remoteExecution && ! $remoteSigning
            && ! $remoteBroadcast && $kill && $remoteKill;
        $live = $remoteLiveHttp && $remoteVerified && ($remote['status'] ?? null) === 'READY'
            && $capable && $pilotReady && $payments && $selfHosted && $delegation && $broadcast
            && $remoteExecution && $remoteSigning && $remoteBroadcast && ! $kill && ! $remoteKill;
        $state = $closed
            ? (($shutdownInvocation || ! $capable) ? 'SHUTDOWN_VERIFIED' : 'SAFE_DEPLOYED')
            : ($live ? 'LIVE_ENABLED' : (($kill || $remoteKill) ? 'ARMED_NOT_LIVE' : 'NOT_VERIFIED'));

        return [
            'state' => $state,
            'live' => $state === 'LIVE_ENABLED',
            'context' => [
                'activation_release_capable' => $capable ? 'YES' : 'NO',
                'payments_mainnet' => $payments ? 'ENABLED' : 'DISABLED',
                'self_hosted_fee_payer' => $selfHosted ? 'ENABLED' : 'DISABLED',
                'mainnet_fee_delegation' => $delegation ? 'ENABLED' : 'DISABLED',
                'laravel_broadcast' => $broadcast ? 'ENABLED' : 'DISABLED',
                'fee_payer_execution' => $remoteExecution ? 'ENABLED' : 'DISABLED',
                'fee_payer_signing' => $remoteSigning ? 'ENABLED' : 'DISABLED',
                'fee_payer_broadcast' => $remoteBroadcast ? 'ENABLED' : 'DISABLED',
                'kill_switch' => ($kill && $remoteKill) ? 'ACTIVE' : ((! $kill && ! $remoteKill) ? 'INACTIVE' : 'MISMATCH'),
                'pilot_payment_id' => (string) $pilotId,
                'attempt_count' => (string) $attemptCount,
                'pilot_policy' => $pilotReady ? 'READY' : 'NOT_READY',
                'local_broadcast' => $broadcast ? 'NOT_DISABLED' : 'DISABLED',
                'fee_payer_gates' => $remoteVerified ? ($remoteExecution || $remoteSigning || $remoteBroadcast ? 'NOT_DISABLED' : 'DISABLED') : 'NOT_VERIFIED',
            ],
        ];
    }
}
