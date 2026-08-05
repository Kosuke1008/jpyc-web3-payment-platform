<?php

namespace App\Services\Payments;

interface FeeDelegatedTransactionInspector
{
    public function inspect(string $senderSignedTransaction): SponsoredTransfer;
}
