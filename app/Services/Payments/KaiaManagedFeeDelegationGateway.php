<?php

namespace App\Services\Payments;

final class KaiaManagedFeeDelegationGateway extends KaiaFeeDelegationHttpGateway
{
    public function provider(): string
    {
        return 'kaia-managed';
    }

    protected function managed(): bool
    {
        return true;
    }
}
