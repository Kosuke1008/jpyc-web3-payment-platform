<?php

namespace App\Console\Commands;

use App\Services\Payments\ManagedKairosTestPreparer;
use App\Services\Payments\PaymentSponsorshipException;
use Illuminate\Console\Command;

final class PrepareManagedKairosTest extends Command
{
    protected $signature = 'payments:prepare-managed-kairos-test
        {payment : The deliberately selected Payment ID}
        {--sender-signed-tx= : Sender-signed type 0x31 RLP; omit to enter it without display}';

    protected $description = 'Validate one managed Kairos payment without signing or broadcasting';

    public function handle(ManagedKairosTestPreparer $preparer): int
    {
        $paymentId = filter_var(
            $this->argument('payment'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $raw = $this->option('sender-signed-tx')
            ?? $this->secret('Sender-signed type 0x31 RLP');

        if (! is_int($paymentId) || ! is_string($raw) || $raw === '') {
            $this->error('dry_run=failed diagnostic=invalid_input');

            return self::FAILURE;
        }

        try {
            $result = $preparer->prepare($paymentId, $raw);
        } catch (PaymentSponsorshipException $exception) {
            $this->error('dry_run=failed diagnostic='.$exception->reason);

            return self::FAILURE;
        }

        $this->table(['field', 'value'], [
            ['payment_id', (string) $result->paymentId],
            ['sender_address', $result->senderAddress],
            ['recipient_address', $result->recipientAddress],
            ['jpyc_amount', $result->displayAmount],
            ['chain_id', (string) $result->chainId],
            ['sender_tx_hash', $result->senderTxHash],
            ['request_fingerprint', $result->requestFingerprint],
        ]);
        $this->line('attempt_written=no');
        $this->line('provider_call=not_performed');
        $this->line('broadcast=not_performed');
        $this->line('dry_run=ready');

        return self::SUCCESS;
    }
}
