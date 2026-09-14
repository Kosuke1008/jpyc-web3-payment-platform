<?php

namespace Tests\Unit;

use App\Payments\MainnetPilotLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MainnetPilotLimitsTest extends TestCase
{
    public function test_exact_decimal_round_trips_beyond_javascript_safe_integer(): void
    {
        foreach (['0', '0.000000000000000001', '9007199254740993.123456789012345678', '0.01375'] as $amount) {
            $this->assertSame($amount, MainnetPilotLimits::weiToKaia(MainnetPilotLimits::kaiaToWei($amount)));
        }
        $this->assertSame('9007199254740993123456789012345678', MainnetPilotLimits::kaiaToWei('9007199254740993.123456789012345678'));
    }

    #[DataProvider('invalidDecimals')]
    public function test_rejects_inexact_or_noncanonical_decimals(mixed $value): void
    {
        $this->expectException(RuntimeException::class);
        MainnetPilotLimits::kaiaToWei($value);
    }

    public static function invalidDecimals(): array
    {
        return [[0.01], [1], ['1e-18'], ['-1'], ['+1'], ['01'], ['.1'], ['1.'], ['0.0000000000000000001'], [null]];
    }
}
