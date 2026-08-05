<?php

namespace App\Services\Payments;

use RuntimeException;
use Throwable;

class PaymentSponsorshipException extends RuntimeException
{
    public const DISABLED = 'disabled';

    public const CONFIGURATION_ERROR = 'configuration_error';

    public const PAYMENT_NOT_FOUND = 'payment_not_found';

    public const PAYMENT_ALREADY_CONFIRMED = 'payment_already_confirmed';

    public const PAYMENT_NOT_PENDING = 'payment_not_pending';

    public const PAYMENT_EXPIRED = 'payment_expired';

    public const INVALID_TRANSACTION = 'invalid_transaction';

    public const SPONSORSHIP_CONFLICT = 'sponsorship_conflict';

    public const RPC_UNAVAILABLE = 'rpc_unavailable';

    public const INVALID_RPC_RESPONSE = 'invalid_rpc_response';

    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const PROVIDER_STATUS_UNKNOWN = 'provider_status_unknown';

    public const PROVIDER_REJECTED = 'provider_rejected';

    public function __construct(
        public readonly string $reason,
        public readonly ?string $externalMethod = null,
        public readonly ?int $upstreamStatus = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($reason, 0, $previous);
    }
}
