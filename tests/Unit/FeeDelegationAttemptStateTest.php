<?php

namespace Tests\Unit;

use App\Services\Payments\FeeDelegationAttemptState;
use App\Services\Payments\KaiaSenderTransactionHash;
use LogicException;
use PHPUnit\Framework\TestCase;

class FeeDelegationAttemptStateTest extends TestCase
{
    private const SDK_SENDER_RLP = '0x31f8c50185066720b300830186a094e7c3d8c9a439fede00d2600032d5db0be71c3c298094a2a8854b1802d8cd5de631e690817c253d6a9153b844a9059cbb00000000000000000000000070997970c51812dc3a010c7d01b50e0d17dc79c80000000000000000000000000000000000000000000000000de0b6b3a7640000f847f8458207f6a052baf55055acf5989c5fcae851e263f7a99bc2403210ebb4e70bcebc7945bed8a0755eadf37734c3f1629836ed938782dd1e40d4ece67910a84c2dafe234fe8c6d';

    public function test_expected_lifecycle_transitions_are_allowed(): void
    {
        $path = [
            FeeDelegationAttemptState::RESERVED,
            FeeDelegationAttemptState::VALIDATED,
            FeeDelegationAttemptState::SUBMITTING,
            FeeDelegationAttemptState::UNKNOWN_SUBMISSION,
            FeeDelegationAttemptState::SUBMITTED,
            FeeDelegationAttemptState::RECEIPT_OBSERVED,
            FeeDelegationAttemptState::CONFIRMED,
        ];

        foreach (array_map(null, array_slice($path, 0, -1), array_slice($path, 1)) as [$from, $to]) {
            FeeDelegationAttemptState::assertTransition($from, $to);
        }

        $this->addToAssertionCount(count($path) - 1);
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $this->expectException(LogicException::class);

        FeeDelegationAttemptState::assertTransition(
            FeeDelegationAttemptState::UNKNOWN_SUBMISSION,
            FeeDelegationAttemptState::CONFIRMED
        );
    }

    public function test_sender_tx_hash_matches_official_sdk_sender_rlp_vector(): void
    {
        $hash = (new KaiaSenderTransactionHash)->fromSenderSignedRlp(
            self::SDK_SENDER_RLP
        );

        $this->assertSame(
            '0xfb00cdc0a693be52d7cb7c3d02a4002e4fc07832b0ebd59d80f8256a1a15356e',
            $hash
        );
    }
}
