<?php

namespace App\Blockchain;

final readonly class MainnetReadinessReport
{
    /**
     * @param  array{ready: bool, diagnostic_code: ?string, chain_id: ?int, latest_block: ?int}  $primary
     * @param  array{ready: bool, diagnostic_code: ?string, chain_id: ?int, latest_block: ?int}  $secondary
     */
    public function __construct(
        public bool $ready,
        public ?string $diagnosticCode,
        public array $primary,
        public array $secondary,
    ) {}
}
