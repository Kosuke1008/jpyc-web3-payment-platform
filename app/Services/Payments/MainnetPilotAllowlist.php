<?php

namespace App\Services\Payments;

use App\Payments\PaymentSnapshot;

final class MainnetPilotAllowlist
{
    public function assertAllowed(
        PaymentSnapshot $snapshot,
        SponsoredTransfer $transfer,
        int $requesterUserId
    ): void {
        $configuration = config(
            'services.fee_delegation.mainnet_staging'
        );

        if (! is_array($configuration)) {
            $this->reject();
        }

        $merchant = $this->address($configuration['merchant_address'] ?? null);
        $feePayer = $this->address($configuration['fee_payer_address'] ?? null);
        $kairosMerchant = $this->address(
            $configuration['kairos_merchant_address'] ?? null
        );
        $kairosFeePayer = $this->address(
            $configuration['kairos_fee_payer_address'] ?? null
        );
        $allowReuse = ($configuration['allow_cross_environment_reuse'] ?? null)
            === true;
        $approvedUsers = $this->csv(
            $configuration['approved_user_ids'] ?? null
        );
        $approvedSenders = array_map(
            'strtolower',
            $this->csv($configuration['approved_sender_addresses'] ?? null)
        );
        $approvedSender = count($approvedSenders) === 1
            ? $this->address($approvedSenders[0])
            : null;

        if ($merchant === null
            || $feePayer === null
            || count($approvedUsers) !== 1
            || preg_match('/\A[1-9][0-9]*\z/', $approvedUsers[0] ?? '') !== 1
            || $approvedSender === null
            || strcasecmp($merchant, $feePayer) === 0
            || strcasecmp($approvedSender, $feePayer) === 0
            || (! $allowReuse && ($kairosMerchant === null
                || $kairosFeePayer === null
                || strcasecmp($merchant, $kairosMerchant) === 0
                || strcasecmp($feePayer, $kairosFeePayer) === 0))
            || strcasecmp($snapshot->recipientAddress, $merchant) !== 0
            || strcasecmp($transfer->recipient, $merchant) !== 0
            || ! in_array(
                (string) $requesterUserId,
                $approvedUsers,
                true
            )
            || ! in_array(
                strtolower($transfer->sender),
                [$approvedSender],
                true
            )) {
            $this->reject();
        }
    }

    private function address(mixed $value): ?string
    {
        return is_string($value)
            && preg_match('/\A0x[0-9a-fA-F]{40}\z/', $value) === 1
                ? strtolower($value)
                : null;
    }

    /** @return list<string> */
    private function csv(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            fn (string $entry): bool => $entry !== ''
        ));
    }

    private function reject(): never
    {
        throw new PaymentSponsorshipException(
            PaymentSponsorshipException::POLICY_REJECTED
        );
    }
}
