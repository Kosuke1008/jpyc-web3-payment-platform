<?php

namespace App\Services\Payments;

enum BroadcastCertainty: string
{
    case DEFINITELY_NOT_BROADCAST = 'definitely_not_broadcast';

    case BROADCAST_POSSIBLE = 'broadcast_possible';

    case SUBMITTED = 'submitted';

    public static function fromProtocol(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
