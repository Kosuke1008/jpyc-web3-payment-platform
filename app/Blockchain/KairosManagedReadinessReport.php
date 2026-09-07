<?php

namespace App\Blockchain;

final readonly class KairosManagedReadinessReport
{
    /** @param array<string, array{ready: bool, diagnostic: string}> $checks */
    public function __construct(
        public bool $ready,
        public array $checks
    ) {}
}
