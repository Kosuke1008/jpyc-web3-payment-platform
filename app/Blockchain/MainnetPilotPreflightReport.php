<?php

namespace App\Blockchain;

final readonly class MainnetPilotPreflightReport
{
    /**
     * @param  array<string, array{ready: bool, diagnostic: string}>  $checks
     * @param  array<string, int|string>  $publicContext
     */
    public function __construct(
        public bool $ready,
        public array $checks,
        public array $publicContext,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'overall' => $this->ready
                ? 'PILOT_PREFLIGHT_READY'
                : 'NOT_READY',
            'infrastructure' => $this->groupReady([
                'staging_readiness',
                'environment_database',
                'disabled_gates',
                'remote_disabled_gates',
                'database_and_cache',
                'rpc',
            ]) ? 'READY' : 'NOT_READY',
            'signer' => $this->checkReady('signer') ? 'READY' : 'NOT_READY',
            'policy' => $this->groupReady([
                'pilot_payment_id',
                'pilot_identity',
                'pilot_limits',
                'pilot_payment',
                'no_existing_attempt',
                'fee_payer_balance',
                'fee_payer_policy',
                'gas_observation',
            ]) ? 'READY' : 'NOT_READY',
            'broadcast' => ! $this->checkReady('broadcast_disabled') ? 'NOT_DISABLED'
                : ($this->checkReady('remote_disabled_gates') ? 'DISABLED' : 'NOT_VERIFIED'),
            'kill_switch' => ! $this->checkReady('kill_switch') ? 'NOT_ACTIVE'
                : ($this->checkReady('remote_disabled_gates') ? 'ACTIVE' : 'NOT_VERIFIED'),
            'checks' => $this->checks,
            'context' => $this->publicContext,
        ];
    }

    /** @param list<string> $names */
    private function groupReady(array $names): bool
    {
        foreach ($names as $name) {
            if (! $this->checkReady($name)) {
                return false;
            }
        }

        return true;
    }

    private function checkReady(string $name): bool
    {
        return ($this->checks[$name]['ready'] ?? false) === true;
    }
}
