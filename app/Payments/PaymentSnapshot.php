<?php

namespace App\Payments;

use App\Blockchain\NetworkProfile;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

final readonly class PaymentSnapshot
{
    private const MAX_UINT64 = '18446744073709551615';

    private const MAX_UINT256 = '115792089237316195423570985008687907853269984665640564039457584007913129639935';

    private const SUPPORTED_NETWORKS = [
        'kairos',
        'kaia-mainnet',
        'sepolia',
    ];

    public function __construct(
        public string $network,
        public int $networkProfileVersion,
        public int $chainId,
        public string $tokenContract,
        public string $tokenSymbol,
        public int $tokenDecimals,
        public string $recipientAddress,
        public string $displayAmount,
        public string $atomicAmount,
        public CarbonImmutable $expiresAt,
    ) {}

    public static function create(
        NetworkProfile $profile,
        mixed $recipientAddress,
        mixed $amount,
        DateTimeInterface|string $expiresAt,
        int $maximumDisplayAmount = 100_000
    ): self {
        $displayAmount = self::canonicalDisplayAmount(
            $amount,
            $maximumDisplayAmount
        );
        $recipient = self::normalizeAddress($recipientAddress);
        $atomicAmount = self::calculateAtomicAmount(
            $displayAmount,
            $profile->jpycDecimals
        );

        return self::validated(
            network: $profile->id,
            networkProfileVersion: $profile->version,
            chainId: $profile->chainId,
            tokenContract: strtolower($profile->jpycContract),
            tokenSymbol: $profile->jpycSymbol,
            tokenDecimals: $profile->jpycDecimals,
            recipientAddress: $recipient,
            displayAmount: $displayAmount,
            atomicAmount: $atomicAmount,
            expiresAt: $expiresAt,
        );
    }

    public static function fromRecord(array|object $record): self
    {
        return self::validated(
            network: self::value($record, 'network'),
            networkProfileVersion: self::value(
                $record,
                'network_profile_version'
            ),
            chainId: self::value($record, 'chain_id'),
            tokenContract: self::value($record, 'token_contract'),
            tokenSymbol: self::value($record, 'token_symbol'),
            tokenDecimals: self::value($record, 'token_decimals'),
            recipientAddress: self::value($record, 'recipient_address'),
            displayAmount: self::value($record, 'display_amount'),
            atomicAmount: self::value($record, 'atomic_amount'),
            expiresAt: self::expirationValue($record),
        );
    }

    /** @return array<string, int|string|CarbonImmutable> */
    public function databaseAttributes(): array
    {
        return [
            'network' => $this->network,
            'network_profile_version' => $this->networkProfileVersion,
            'chain_id' => $this->chainId,
            'token_contract' => $this->tokenContract,
            'token_symbol' => $this->tokenSymbol,
            'token_decimals' => $this->tokenDecimals,
            'recipient_address' => $this->recipientAddress,
            'display_amount' => $this->displayAmount,
            'atomic_amount' => $this->atomicAmount,
            'expires_at' => $this->expiresAt,
        ];
    }

    public function hasSameSemantics(self $other): bool
    {
        return $this->network === $other->network
            && $this->networkProfileVersion === $other->networkProfileVersion
            && $this->chainId === $other->chainId
            && $this->tokenContract === $other->tokenContract
            && $this->tokenSymbol === $other->tokenSymbol
            && $this->tokenDecimals === $other->tokenDecimals
            && $this->recipientAddress === $other->recipientAddress
            && $this->displayAmount === $other->displayAmount
            && $this->atomicAmount === $other->atomicAmount
            && $this->expiresAt->equalTo($other->expiresAt);
    }

    private static function validated(
        mixed $network,
        mixed $networkProfileVersion,
        mixed $chainId,
        mixed $tokenContract,
        mixed $tokenSymbol,
        mixed $tokenDecimals,
        mixed $recipientAddress,
        mixed $displayAmount,
        mixed $atomicAmount,
        mixed $expiresAt,
    ): self {
        if (! is_string($network)
            || ! in_array($network, self::SUPPORTED_NETWORKS, true)) {
            throw new InvalidPaymentSnapshotException(
                'Payment network snapshot is invalid.'
            );
        }

        $version = self::positiveInteger($networkProfileVersion);
        $chain = self::positiveInteger($chainId);
        $decimals = self::byteInteger($tokenDecimals);

        if (! is_string($tokenContract)
            || preg_match('/\A0x[0-9a-f]{40}\z/', $tokenContract) !== 1
            || ! is_string($recipientAddress)
            || preg_match('/\A0x[0-9a-f]{40}\z/', $recipientAddress) !== 1) {
            throw new InvalidPaymentSnapshotException(
                'Payment address snapshot is invalid.'
            );
        }

        if (! is_string($tokenSymbol)
            || preg_match('/\A[A-Z0-9]{1,16}\z/', $tokenSymbol) !== 1) {
            throw new InvalidPaymentSnapshotException(
                'Payment token snapshot is invalid.'
            );
        }

        $display = self::canonicalStoredDisplayAmount($displayAmount);

        if (! is_string($atomicAmount)
            || preg_match('/\A[1-9][0-9]{0,77}\z/', $atomicAmount) !== 1
            || bccomp($atomicAmount, self::MAX_UINT256, 0) > 0
            || bccomp(
                $atomicAmount,
                self::calculateAtomicAmount($display, $decimals),
                0
            ) !== 0) {
            throw new InvalidPaymentSnapshotException(
                'Payment atomic amount snapshot is invalid.'
            );
        }

        if ((! is_string($expiresAt) || trim($expiresAt) === '')
            && ! $expiresAt instanceof DateTimeInterface) {
            throw new InvalidPaymentSnapshotException(
                'Payment expiration snapshot is invalid.'
            );
        }

        try {
            $expiration = $expiresAt instanceof DateTimeInterface
                ? CarbonImmutable::instance($expiresAt)
                : CarbonImmutable::parse(
                    $expiresAt,
                    date_default_timezone_get()
                );
        } catch (Throwable $exception) {
            throw new InvalidPaymentSnapshotException(
                'Payment expiration snapshot is invalid.',
                previous: $exception
            );
        }

        return new self(
            network: $network,
            networkProfileVersion: $version,
            chainId: $chain,
            tokenContract: $tokenContract,
            tokenSymbol: $tokenSymbol,
            tokenDecimals: $decimals,
            recipientAddress: $recipientAddress,
            displayAmount: $display,
            atomicAmount: $atomicAmount,
            expiresAt: $expiration,
        );
    }

    private static function canonicalDisplayAmount(
        mixed $amount,
        int $maximum
    ): string {
        if ($maximum < 1) {
            throw new InvalidPaymentSnapshotException(
                'Payment maximum amount configuration is invalid.'
            );
        }

        if (is_int($amount)) {
            $value = (string) $amount;
        } elseif (is_string($amount)) {
            $value = $amount;
        } else {
            throw new InvalidPaymentSnapshotException(
                'Payment amount must be a canonical integer.'
            );
        }

        if (preg_match('/\A[1-9][0-9]*\z/', $value) !== 1
            || bccomp($value, (string) $maximum, 0) > 0) {
            throw new InvalidPaymentSnapshotException(
                'Payment amount is outside the supported range.'
            );
        }

        return $value;
    }

    private static function canonicalStoredDisplayAmount(mixed $amount): string
    {
        if ((! is_int($amount) && ! is_string($amount))
            || preg_match('/\A[1-9][0-9]{0,19}\z/', (string) $amount) !== 1
            || bccomp((string) $amount, self::MAX_UINT64, 0) > 0) {
            throw new InvalidPaymentSnapshotException(
                'Payment display amount snapshot is invalid.'
            );
        }

        return (string) $amount;
    }

    private static function calculateAtomicAmount(
        string $displayAmount,
        int $decimals
    ): string {
        $atomicAmount = bcmul(
            $displayAmount,
            bcpow('10', (string) $decimals, 0),
            0
        );

        if (strlen($atomicAmount) > 78
            || bccomp($atomicAmount, self::MAX_UINT256, 0) > 0) {
            throw new InvalidPaymentSnapshotException(
                'Payment amount exceeds uint256.'
            );
        }

        return $atomicAmount;
    }

    private static function normalizeAddress(mixed $address): string
    {
        if (! is_string($address)
            || preg_match('/\A0x[0-9a-fA-F]{40}\z/', $address) !== 1) {
            throw new InvalidPaymentSnapshotException(
                'Payment recipient address is invalid.'
            );
        }

        return strtolower($address);
    }

    private static function positiveInteger(mixed $value): int
    {
        if ((! is_int($value) && ! is_string($value))
            || preg_match('/\A[1-9][0-9]*\z/', (string) $value) !== 1) {
            throw new InvalidPaymentSnapshotException(
                'Payment numeric snapshot is invalid.'
            );
        }

        $integer = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (! is_int($integer)) {
            throw new InvalidPaymentSnapshotException(
                'Payment numeric snapshot is invalid.'
            );
        }

        return $integer;
    }

    private static function byteInteger(mixed $value): int
    {
        if ((! is_int($value) && ! is_string($value))
            || preg_match('/\A[0-9]+\z/', (string) $value) !== 1) {
            throw new InvalidPaymentSnapshotException(
                'Payment decimals snapshot is invalid.'
            );
        }

        $integer = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 255]]
        );

        if (! is_int($integer)) {
            throw new InvalidPaymentSnapshotException(
                'Payment decimals snapshot is invalid.'
            );
        }

        return $integer;
    }

    private static function value(array|object $record, string $field): mixed
    {
        if (is_array($record)) {
            return $record[$field] ?? null;
        }

        return $record->{$field} ?? null;
    }

    private static function expirationValue(array|object $record): mixed
    {
        if (is_object($record)
            && method_exists($record, 'getRawOriginal')) {
            $raw = $record->getRawOriginal('expires_at');

            if (is_string($raw) && $raw !== '') {
                return $raw;
            }
        }

        return self::value($record, 'expires_at');
    }
}
