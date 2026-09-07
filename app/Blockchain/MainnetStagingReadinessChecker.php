<?php

namespace App\Blockchain;

use Illuminate\Support\Facades\Http;
use Throwable;

final class MainnetStagingReadinessChecker
{
    private const REQUIRED_MIGRATIONS = [
        '2026_09_04_000000_add_payment_snapshot_to_payments_table',
        '2026_09_06_000000_add_confirmation_evidence_to_payments_table',
        '2026_09_06_500000_create_payment_fee_delegation_attempts_table',
    ];

    private const STAGE2_MIGRATION =
        '2026_09_07_000000_harden_payments_for_chain_aware_uniqueness';

    public function __construct(
        private readonly NetworkProfileRegistry $networks,
        private readonly MainnetReadinessChecker $rpcReadiness,
        private readonly MainnetStagingInfrastructure $infrastructure,
    ) {}

    public function check(): MainnetStagingReadinessReport
    {
        $checks = [];
        $context = [];
        $configuration = config('services.fee_delegation.mainnet_staging');
        $configuration = is_array($configuration) ? $configuration : [];

        try {
            $profile = $this->networks->active();
            $kairos = $this->networks->get('kairos');
        } catch (Throwable) {
            $profile = null;
            $kairos = null;
        }

        $this->record(
            $checks,
            'environment',
            ($configuration['environment_id'] ?? null) === 'mainnet-staging',
            'environment_not_mainnet_staging'
        );
        $this->record(
            $checks,
            'network_profile',
            $profile?->id === 'kaia-mainnet' && $profile->chainId === 8217,
            'mainnet_profile_invalid'
        );
        $this->record(
            $checks,
            'execution_gates',
            $profile !== null
                && ! $profile->paymentExecutionEnabled
                && ! $profile->feeDelegationExecutionEnabled
                && config('blockchain.payments_mainnet_enabled') === false
                && config('blockchain.mainnet_fee_delegation_enabled') === false
                && config('blockchain.mainnet_broadcast_enabled') === false
                && config('services.fee_delegation.self_hosted_mainnet.enabled') === false
                && config('services.fee_delegation.self_hosted_mainnet.kill_switch') === true,
            'mainnet_execution_gate_open'
        );
        $this->record(
            $checks,
            'provider_selection',
            config('services.fee_delegation.mode') === 'self-hosted',
            'provider_not_self_hosted'
        );

        $rpcUrlsSafe = $profile !== null
            && $kairos !== null
            && $profile->secondaryRpcUrl !== null
            && $profile->rpcUrl !== $profile->secondaryRpcUrl
            && $profile->rpcUrl !== $kairos->rpcUrl
            && $profile->secondaryRpcUrl !== $kairos->rpcUrl
            && $this->commercialRpc($profile->rpcUrl)
            && $this->commercialRpc($profile->secondaryRpcUrl);
        $this->record(
            $checks,
            'rpc_separation',
            $rpcUrlsSafe,
            'mainnet_rpc_not_dedicated'
        );

        $rpc = $this->rpcReadiness->check();
        $this->record($checks, 'primary_rpc', $rpc->primary['ready'], 'primary_rpc_unavailable');
        $this->record($checks, 'secondary_rpc', $rpc->secondary['ready'], 'secondary_rpc_unavailable');
        $this->record($checks, 'jpyc_contract', $rpc->ready, 'jpyc_or_rpc_readiness_failed');

        $database = $this->infrastructure->database();
        $databaseIdentifier = $configuration['database_identifier'] ?? null;
        $kairosDatabase = $configuration['kairos_database_identifier'] ?? null;
        $databaseSeparated = $database['ready']
            && is_string($databaseIdentifier)
            && $databaseIdentifier !== ''
            && $database['name'] === $databaseIdentifier
            && is_string($kairosDatabase)
            && $kairosDatabase !== ''
            && $databaseIdentifier !== $kairosDatabase;
        $this->record($checks, 'database', $databaseSeparated, 'database_not_isolated');

        $requiredMigrationsReady = count(array_diff(
            self::REQUIRED_MIGRATIONS,
            $database['migrations']
        )) === 0;
        $this->record(
            $checks,
            'migration_state',
            $requiredMigrationsReady,
            'required_migration_missing'
        );
        $stage2Applied = in_array(
            self::STAGE2_MIGRATION,
            $database['migrations'],
            true
        );
        $stage2Resolved = ($configuration['stage2_legacy_policy_resolved'] ?? null)
            === true;
        $checks['stage2_hardening'] = [
            'ready' => $stage2Applied || ! $stage2Resolved,
            'diagnostic' => $stage2Applied
                ? 'applied'
                : ($stage2Resolved
                    ? 'migration_pending_after_policy_resolution'
                    : 'blocked_pending_legacy_policy'),
        ];

        $cache = $this->infrastructure->cacheLock();
        $stagingPrefix = $configuration['cache_prefix'] ?? null;
        $kairosPrefix = $configuration['kairos_cache_prefix'] ?? null;
        $this->record(
            $checks,
            'cache_lock',
            $cache['ready']
                && is_string($stagingPrefix)
                && $stagingPrefix !== ''
                && $cache['prefix'] === $stagingPrefix
                && is_string($kairosPrefix)
                && $kairosPrefix !== ''
                && $stagingPrefix !== $kairosPrefix,
            'shared_cache_lock_unavailable'
        );

        $merchant = $this->address($configuration['merchant_address'] ?? null);
        $feePayer = $this->address($configuration['fee_payer_address'] ?? null);
        $kairosMerchant = $this->address($configuration['kairos_merchant_address'] ?? null);
        $kairosFeePayer = $this->address($configuration['kairos_fee_payer_address'] ?? null);
        $reuseAllowed = ($configuration['allow_cross_environment_reuse'] ?? null) === true;
        $identitiesReady = $merchant !== null
            && $feePayer !== null
            && $merchant !== $feePayer
            && ($reuseAllowed || ($kairosMerchant !== null
                && $kairosFeePayer !== null
                && $merchant !== $kairosMerchant
                && $feePayer !== $kairosFeePayer));
        $this->record($checks, 'identity_separation', $identitiesReady, 'identity_not_isolated');

        $users = array_filter(
            $this->csv($configuration['approved_user_ids'] ?? null),
            fn (string $id): bool => preg_match('/\A[1-9][0-9]*\z/', $id) === 1
        );
        $senders = array_filter(
            $this->csv($configuration['approved_sender_addresses'] ?? null),
            fn (string $address): bool => $this->address($address) !== null
        );
        $this->record(
            $checks,
            'pilot_allowlist',
            count($users) >= 1 && count($senders) >= 1 && $merchant !== null,
            'pilot_allowlist_empty'
        );

        $health = $this->feePayerHealth(
            $configuration['fee_payer_health_url'] ?? null
        );
        $healthReady = is_array($health)
            && ($health['status'] ?? null) === 'STRUCTURALLY_READY'
            && ($health['network'] ?? null) === 'kaia-mainnet'
            && ($health['chain_id'] ?? null) === 8217
            && $this->address($health['fee_payer_address'] ?? null) === $feePayer
            && ($health['signer_status'] ?? null) === 'UNAVAILABLE'
            && ($health['kill_switch'] ?? null) === 'ACTIVE'
            && ($health['execution'] ?? null) === 'DISABLED'
            && ($health['signing'] ?? null) === 'DISABLED'
            && ($health['broadcast'] ?? null) === 'DISABLED'
            && is_string($health['balance_kaia'] ?? null)
            && preg_match('/\A(0|[1-9][0-9]*)(\.[0-9]+)?\z/', $health['balance_kaia']) === 1;
        $this->record($checks, 'fee_payer_service', $healthReady, 'fee_payer_health_invalid');
        $this->record($checks, 'signer_state', $healthReady, 'signer_state_invalid');
        $this->record($checks, 'balance', $healthReady, 'fee_payer_balance_unreadable');

        if ($merchant !== null) {
            $context['merchant_address'] = $merchant;
        }
        if ($feePayer !== null) {
            $context['fee_payer_address'] = $feePayer;
        }
        if (is_array($health) && is_string($health['balance_kaia'] ?? null)) {
            $context['fee_payer_balance_kaia'] = $health['balance_kaia'];
        }
        $context['migration_count'] = count($database['migrations']);
        $context['approved_user_count'] = count($users);
        $context['approved_sender_count'] = count($senders);

        return new MainnetStagingReadinessReport(
            ! in_array(false, array_column($checks, 'ready'), true),
            $checks,
            $context
        );
    }

    private function feePayerHealth(mixed $url): ?array
    {
        if (! is_string($url) || ! $this->loopbackHealthUrl($url)) {
            return null;
        }

        try {
            $response = Http::timeout(5)
                ->withOptions(['allow_redirects' => false])
                ->get($url);
            if (! $response->successful() || strlen($response->body()) > 16384) {
                return null;
            }
            $body = $response->json();

            return is_array($body) ? $body : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function loopbackHealthUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'http'
            && ($parts['host'] ?? null) === '127.0.0.1'
            && ($parts['path'] ?? null) === '/health'
            && ! isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment']);
    }

    private function commercialRpc(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== ''
            && ! in_array($host, [
                'public-en.node.kaia.io',
                'archive-en.node.kaia.io',
                'public-en-kairos.node.kaia.io',
                'archive-en-kairos.node.kaia.io',
            ], true);
    }

    private function address(mixed $value): ?string
    {
        return is_string($value)
            && preg_match('/\A0x[0-9a-fA-F]{40}\z/', $value) === 1
                ? strtolower($value)
                : null;
    }

    /** @return list<string> */
    private function csv(mixed $value): array
    {
        return is_string($value)
            ? array_values(array_filter(
                array_map('trim', explode(',', $value)),
                fn (string $entry): bool => $entry !== ''
            ))
            : [];
    }

    /** @param array<string, array{ready: bool, diagnostic: string}> $checks */
    private function record(
        array &$checks,
        string $name,
        bool $ready,
        string $failureDiagnostic
    ): void {
        $checks[$name] = [
            'ready' => $ready,
            'diagnostic' => $ready ? 'none' : $failureDiagnostic,
        ];
    }
}
