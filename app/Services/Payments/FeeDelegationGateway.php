<?php

namespace App\Services\Payments;

interface FeeDelegationGateway
{
    public function sponsor(string $senderSignedTransaction): string;
}
