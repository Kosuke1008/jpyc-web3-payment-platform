<?php

namespace App\Payments;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use RuntimeException;
use Throwable;

final readonly class PaymentConfirmationEvidence
{
    public function __construct(
        public int $observedChainId,
        public string $transactionHash,
        public int $blockNumber,
        public string $blockHash,
        public int $receiptStatus,
        public string $payerAddress,
        public int $transferLogIndex,
        public CarbonImmutable $chainConfirmedAt,
        public CarbonImmutable $verifiedAt,
    ) {}

    public static function create(
        mixed $observedChainId,
        mixed $transactionHash,
        mixed $blockNumber,
        mixed $blockHash,
        mixed $receiptStatus,
        mixed $payerAddress,
        mixed $transferLogIndex,
        DateTimeInterface|string $chainConfirmedAt,
        DateTimeInterface|string $verifiedAt,
    ): self {
        $chainId = self::positiveInteger($observedChainId);
        $number = self::positiveInteger($blockNumber);
        $status = self::nonNegativeInteger($receiptStatus);
        $logIndex = self::nonNegativeInteger($transferLogIndex);
        $transaction = self::hash($transactionHash);
        $block = self::hash($blockHash);
        $payer = self::address($payerAddress);

        if ($status !== 1
            || $payer === '0x0000000000000000000000000000000000000000') {
            throw new RuntimeException('Payment confirmation evidence is invalid.');
        }

        return new self(
            observedChainId: $chainId,
            transactionHash: $transaction,
            blockNumber: $number,
            blockHash: $block,
            receiptStatus: $status,
            payerAddress: $payer,
            transferLogIndex: $logIndex,
            chainConfirmedAt: self::date($chainConfirmedAt),
            verifiedAt: self::date($verifiedAt),
        );
    }

    public static function fromRecord(array|object $record): self
    {
        return self::create(
            observedChainId: self::value($record, 'observed_chain_id'),
            transactionHash: self::value($record, 'tx_hash'),
            blockNumber: self::value($record, 'confirmed_block_number'),
            blockHash: self::value($record, 'confirmed_block_hash'),
            receiptStatus: self::value($record, 'receipt_status'),
            payerAddress: self::value($record, 'payer_address'),
            transferLogIndex: self::value($record, 'transfer_log_index'),
            chainConfirmedAt: self::dateValue($record, 'chain_confirmed_at'),
            verifiedAt: self::dateValue($record, 'verified_at'),
        );
    }

    /** @return array<string, int|string|CarbonImmutable> */
    public function databaseAttributes(): array
    {
        return [
            'observed_chain_id' => $this->observedChainId,
            'tx_hash' => $this->transactionHash,
            'confirmed_block_number' => $this->blockNumber,
            'confirmed_block_hash' => $this->blockHash,
            'receipt_status' => $this->receiptStatus,
            'payer_address' => $this->payerAddress,
            'transfer_log_index' => $this->transferLogIndex,
            'chain_confirmed_at' => $this->chainConfirmedAt,
            'verified_at' => $this->verifiedAt,
        ];
    }

    public function matches(self $other): bool
    {
        return $this->observedChainId === $other->observedChainId
            && $this->transactionHash === $other->transactionHash
            && $this->blockNumber === $other->blockNumber
            && $this->blockHash === $other->blockHash
            && $this->receiptStatus === $other->receiptStatus
            && $this->payerAddress === $other->payerAddress
            && $this->transferLogIndex === $other->transferLogIndex
            && $this->chainConfirmedAt->equalTo($other->chainConfirmedAt);
    }

    private static function hash(mixed $value): string
    {
        if (! is_string($value)
            || preg_match('/\A0x[0-9a-fA-F]{64}\z/', $value) !== 1) {
            throw new RuntimeException('Payment evidence hash is invalid.');
        }

        return strtolower($value);
    }

    private static function address(mixed $value): string
    {
        if (! is_string($value)
            || preg_match('/\A0x[0-9a-fA-F]{40}\z/', $value) !== 1) {
            throw new RuntimeException('Payment evidence address is invalid.');
        }

        return strtolower($value);
    }

    private static function positiveInteger(mixed $value): int
    {
        $integer = self::integer($value);

        if ($integer < 1) {
            throw new RuntimeException('Payment evidence number is invalid.');
        }

        return $integer;
    }

    private static function nonNegativeInteger(mixed $value): int
    {
        return self::integer($value);
    }

    private static function integer(mixed $value): int
    {
        if ((! is_int($value) && ! is_string($value))
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/', (string) $value) !== 1) {
            throw new RuntimeException('Payment evidence number is invalid.');
        }

        $integer = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]]
        );

        if (! is_int($integer)) {
            throw new RuntimeException('Payment evidence number is invalid.');
        }

        return $integer;
    }

    private static function date(DateTimeInterface|string $value): CarbonImmutable
    {
        if (is_string($value) && trim($value) === '') {
            throw new RuntimeException('Payment evidence timestamp is invalid.');
        }

        try {
            return $value instanceof DateTimeInterface
                ? CarbonImmutable::instance($value)
                : CarbonImmutable::parse($value, date_default_timezone_get());
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Payment evidence timestamp is invalid.',
                previous: $exception
            );
        }
    }

    private static function value(array|object $record, string $field): mixed
    {
        return is_array($record)
            ? ($record[$field] ?? null)
            : ($record->{$field} ?? null);
    }

    private static function dateValue(array|object $record, string $field): mixed
    {
        if (is_object($record) && method_exists($record, 'getRawOriginal')) {
            $raw = $record->getRawOriginal($field);

            if (is_string($raw) && $raw !== '') {
                return $raw;
            }
        }

        return self::value($record, $field);
    }
}
