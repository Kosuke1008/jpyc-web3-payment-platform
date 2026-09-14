<?php

namespace App\Console\Commands;

use App\Blockchain\MainnetPilotRpc;
use App\Blockchain\MainnetReadinessChecker;
use App\Blockchain\MainnetStagingReadinessChecker;
use App\Blockchain\NetworkProfileRegistry;
use App\Models\Payment;
use App\Models\PaymentFeeDelegationAttempt;
use App\Payments\MainnetPilotGuard;
use App\Payments\MainnetPilotPaymentHistory;
use App\Payments\PaymentSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

final class MainnetPilotGateStatus extends Command
{
    protected $signature = 'mainnet:pilot-gate-status';

    protected $aliases = ['mainnet:pilot-shutdown-check'];

    protected $description = 'Read-only verification that local and Fee Payer Mainnet gates are closed';

    public function handle(
        MainnetPilotGuard $guard,
        MainnetStagingReadinessChecker $staging,
        NetworkProfileRegistry $networks,
        MainnetPilotPaymentHistory $history,
        MainnetPilotRpc $rpc
    ): int {
        try {
            $guard->assertDatabase();
            $report = $staging->check(readOnly: true);
            $url = config('services.fee_delegation.mainnet_staging.fee_payer_health_url');
            $parts = is_string($url) ? parse_url($url) : false;
            $safeUrl = is_array($parts)
                && ($parts['scheme'] ?? null) === 'http'
                && ($parts['host'] ?? null) === '127.0.0.1'
                && ($parts['path'] ?? null) === '/health'
                && ! isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment']);
            $remote = $safeUrl
                ? Http::timeout(5)->withOptions(['allow_redirects' => false])->get($url)->json()
                : null;
            $attemptCount = PaymentFeeDelegationAttempt::query()->count();
        } catch (Throwable) {
            $report = null;
            $remote = null;
            $attemptCount = -1;
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
            config('services.fee_delegation.mainnet_staging.pilot_payment_id')
        );
        $policy = is_array($remote) && is_array($remote['pilot_policy'] ?? null)
            ? $remote['pilot_policy'] : [];
        $balance = is_array($remote) ? ($remote['balance_wei'] ?? null) : null;
        $minimum = $policy['minimum_reserve_wei'] ?? null;
        $maximum = $policy['maximum_balance_wei'] ?? null;
        $maxGas = $policy['max_gas'] ?? null;
        $maxGasPrice = $policy['max_gas_price_wei'] ?? null;
        $pilotReady = $pilotId !== null && ($policy['ready'] ?? null) === true
            && ($policy['pilot_payment_id'] ?? null) === (string) $pilotId
            && is_string($balance) && preg_match('/\A[0-9]+\z/', $balance) === 1
            && is_string($minimum) && preg_match('/\A[0-9]+\z/', $minimum) === 1
            && is_string($maximum) && preg_match('/\A[0-9]+\z/', $maximum) === 1
            && is_string($maxGas) && preg_match('/\A[1-9][0-9]*\z/', $maxGas) === 1
            && is_string($maxGasPrice) && preg_match('/\A[1-9][0-9]*\z/', $maxGasPrice) === 1
            && bccomp($balance, bcadd($minimum, bcmul($maxGas, $maxGasPrice, 0), 0), 0) >= 0
            && bccomp($balance, $maximum, 0) <= 0
            && $attemptCount === 0;
        try {
            $payment = $pilotId === null ? null : Payment::find($pilotId);
            $snapshot = $payment === null ? null : PaymentSnapshot::fromRecord($payment);
            $profile = $networks->active();
            $identities = $guard->identities();
            $pilotReady = $pilotReady && $payment?->status === 'pending'
                && $snapshot?->expiresAt->gt(now()) === true
                && $payment->id === Payment::query()->max('id')
                && $history->supportsCurrent($payment, $identities, $profile)
                && $payment->store_id === $identities['store']->id
                && $payment->staff_id === $identities['staff']->id
                && $payment->user_id === null
                && $payment->tx_hash === null
                && (string) $payment->amount === '1'
                && $snapshot->network === 'kaia-mainnet'
                && $snapshot->networkProfileVersion === $profile->version
                && $snapshot->chainId === 8217
                && $snapshot->tokenContract === MainnetReadinessChecker::JPYC_CONTRACT
                && $snapshot->tokenSymbol === 'JPYC'
                && $snapshot->tokenDecimals === 18
                && $snapshot->recipientAddress === $identities['merchant']
                && $snapshot->displayAmount === '1'
                && $snapshot->atomicAmount === '1000000000000000000';
        } catch (Throwable) {
            $pilotReady = false;
        }
        if ($pilotReady) {
            try {
                $gas = $rpc->gas($identities['sender'], $identities['merchant']);
                $pilotReady = bccomp($gas['observed_required_gas'], $maxGas, 0) <= 0
                    && bccomp($gas['observed_gas_price_wei'], $maxGasPrice, 0) <= 0;
            } catch (Throwable) {
                $pilotReady = false;
            }
        }
        $closed = ! $payments && ! $selfHosted && ! $delegation && ! $broadcast
            && $remoteVerified && ! $remoteExecution && ! $remoteSigning
            && ! $remoteBroadcast && $kill && $remoteKill;
        $live = $capable && $pilotReady && $payments && $selfHosted && $delegation && $broadcast
            && $remoteExecution && $remoteSigning && $remoteBroadcast && ! $kill && ! $remoteKill;
        $shutdownInvocation = $this->input->getFirstArgument() === 'mainnet:pilot-shutdown-check';
        $state = $closed
            ? (($shutdownInvocation || ! $capable) ? 'SHUTDOWN_VERIFIED' : 'SAFE_DEPLOYED')
            : ($live ? 'LIVE_ENABLED' : (($kill || $remoteKill) ? 'ARMED_NOT_LIVE' : 'NOT_VERIFIED'));

        $this->line('overall='.$state);
        $this->line('activation_release_capable='.($capable ? 'YES' : 'NO'));
        $this->line('payments_mainnet='.($payments ? 'ENABLED' : 'DISABLED'));
        $this->line('self_hosted_fee_payer='.($selfHosted ? 'ENABLED' : 'DISABLED'));
        $this->line('mainnet_fee_delegation='.($delegation ? 'ENABLED' : 'DISABLED'));
        $this->line('laravel_broadcast='.($broadcast ? 'ENABLED' : 'DISABLED'));
        $this->line('fee_payer_execution='.($remoteExecution ? 'ENABLED' : 'DISABLED'));
        $this->line('fee_payer_signing='.($remoteSigning ? 'ENABLED' : 'DISABLED'));
        $this->line('fee_payer_broadcast='.($remoteBroadcast ? 'ENABLED' : 'DISABLED'));
        $this->line('kill_switch='.(($kill && $remoteKill) ? 'ACTIVE' : ((! $kill && ! $remoteKill) ? 'INACTIVE' : 'MISMATCH')));
        $this->line('pilot_payment_id='.(string) $pilotId);
        $this->line('attempt_count='.$attemptCount);
        $this->line('pilot_policy='.($pilotReady ? 'READY' : 'NOT_READY'));
        $this->line('local_broadcast='.($broadcast ? 'NOT_DISABLED' : 'DISABLED'));
        $this->line('fee_payer_gates='.($remoteVerified ? ($remoteExecution || $remoteSigning || $remoteBroadcast ? 'NOT_DISABLED' : 'DISABLED') : 'NOT_VERIFIED'));

        return in_array($state, ['SAFE_DEPLOYED', 'LIVE_ENABLED', 'SHUTDOWN_VERIFIED'], true)
            ? self::SUCCESS : self::FAILURE;
    }
}
