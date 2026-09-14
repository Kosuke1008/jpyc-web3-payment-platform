<?php

namespace App\Blockchain;

final readonly class MainnetStagingReadinessReport
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
            'overall' => $this->ready ? 'READ_ONLY_READY' : 'NOT_READY',
            'signing' => ($this->checks['signer_state']['ready'] ?? false)
                ? 'SIGNER_READY'
                : 'SIGNER_NOT_READY',
            'broadcast' => 'DISABLED',
            'checks' => $this->checks,
            'context' => $this->publicContext,
        ];
    }
}
