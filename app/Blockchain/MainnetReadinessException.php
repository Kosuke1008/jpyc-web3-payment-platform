<?php

namespace App\Blockchain;

use RuntimeException;

final class MainnetReadinessException extends RuntimeException
{
    public function __construct(public readonly string $diagnosticCode)
    {
        parent::__construct('Mainnet read-only readiness failed.');
    }
}
