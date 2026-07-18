<?php

namespace App\Services\Payments;

use RuntimeException;

class PaymentVerificationException extends RuntimeException
{
    public const INVALID_TRANSACTION_HASH = 'invalid_transaction_hash';

    public const PAYMENT_NOT_FOUND = 'payment_not_found';

    public const PAYMENT_ALREADY_CONFIRMED = 'payment_already_confirmed';

    public const PAYMENT_NOT_PENDING = 'payment_not_pending';

    public const PAYMENT_EXPIRED = 'payment_expired';

    public const DUPLICATE_TRANSACTION_HASH = 'duplicate_transaction_hash';

    public const RPC_URL_NOT_CONFIGURED = 'rpc_url_not_configured';

    public const TOKEN_CONTRACT_NOT_CONFIGURED = 'token_contract_not_configured';

    public const CHAIN_ID_NOT_CONFIGURED = 'chain_id_not_configured';

    public const INVALID_CHAIN_ID_RESPONSE = 'invalid_chain_id_response';

    public const CHAIN_ID_MISMATCH = 'chain_id_mismatch';

    public const TRANSACTION_NOT_FOUND = 'transaction_not_found';

    public const TRANSACTION_FAILED = 'transaction_failed';

    public const INVALID_TRANSACTION = 'invalid_transaction';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
