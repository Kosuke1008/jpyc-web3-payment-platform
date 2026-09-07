<?php

namespace App\Services\Payments;

use LogicException;

final class FeeDelegationAttemptState
{
    public const RESERVED = 'reserved';

    public const VALIDATED = 'validated';

    public const SUBMITTING = 'submitting';

    public const SUBMITTED = 'submitted';

    public const RECEIPT_OBSERVED = 'receipt_observed';

    public const CONFIRMED = 'confirmed';

    public const REJECTED = 'rejected';

    public const UNKNOWN_SUBMISSION = 'unknown_submission';

    public const REVERTED = 'reverted';

    public const FAILED = 'failed';

    private const TRANSITIONS = [
        self::RESERVED => [self::VALIDATED, self::FAILED],
        self::VALIDATED => [self::SUBMITTING, self::FAILED],
        self::SUBMITTING => [
            self::SUBMITTED,
            self::REJECTED,
            self::UNKNOWN_SUBMISSION,
            self::REVERTED,
            self::FAILED,
        ],
        self::UNKNOWN_SUBMISSION => [
            self::SUBMITTED,
            self::RECEIPT_OBSERVED,
            self::REVERTED,
        ],
        self::SUBMITTED => [
            self::RECEIPT_OBSERVED,
            self::REVERTED,
        ],
        self::RECEIPT_OBSERVED => [self::CONFIRMED, self::REVERTED],
        self::CONFIRMED => [],
        self::REJECTED => [],
        self::REVERTED => [],
        self::FAILED => [],
    ];

    public static function assertTransition(string $from, string $to): void
    {
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new LogicException("Invalid fee delegation attempt transition: {$from} -> {$to}");
        }
    }
}
