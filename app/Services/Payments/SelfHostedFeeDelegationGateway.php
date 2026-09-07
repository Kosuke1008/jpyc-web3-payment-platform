<?php

namespace App\Services\Payments;

final class SelfHostedFeeDelegationGateway extends KaiaFeeDelegationHttpGateway
{
    public function provider(): string
    {
        return 'self-hosted';
    }

    protected function managed(): bool
    {
        return false;
    }
}
