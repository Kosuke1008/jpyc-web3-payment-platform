<?php

namespace App\Payments;

use RuntimeException;

final class MainnetPilotLimits
{
    /** @return array<string, string> */
    public function validate(): array
    {
        $config = config('services.fee_delegation.self_hosted_mainnet', []);
        $values = [];
        foreach (['max_payment_jpy', 'max_attempts_per_user', 'max_attempts_per_store',
            'max_attempts_per_sender', 'max_attempts_global', 'daily_transaction_limit'] as $key) {
            if (($values[$key] = self::integer($config[$key] ?? null)) !== '1') {
                throw new RuntimeException('pilot_limit_must_equal_one');
            }
        }
        foreach (['max_gas', 'max_gas_price_wei', 'rate_window_seconds'] as $key) {
            $values[$key] = self::integer($config[$key] ?? null);
        }
        if (bccomp($values['rate_window_seconds'], '3600') < 0
            || bccomp($values['rate_window_seconds'], '86400') > 0
            || bccomp($values['max_gas'], '1000000') > 0) {
            throw new RuntimeException('pilot_gas_or_window_outside_bounds');
        }
        $values['daily_kaia_budget_wei'] = self::kaiaToWei($config['daily_kaia_budget'] ?? null);
        $values['minimum_reserve_wei'] = self::kaiaToWei($config['minimum_reserve_kaia'] ?? null);
        $values['maximum_balance_wei'] = self::kaiaToWei(config('services.fee_delegation.mainnet_staging.pilot_max_fee_payer_balance_kaia'));
        $values['max_transaction_fee_wei'] = bcmul($values['max_gas'], $values['max_gas_price_wei'], 0);
        $values['minimum_required_balance_wei'] = bcadd($values['max_transaction_fee_wei'], $values['minimum_reserve_wei'], 0);
        if (bccomp($values['minimum_reserve_wei'], '0') <= 0
            || bccomp($values['daily_kaia_budget_wei'], $values['max_transaction_fee_wei']) < 0
            || bccomp($values['daily_kaia_budget_wei'], $values['maximum_balance_wei']) > 0
            || bccomp($values['minimum_required_balance_wei'], $values['maximum_balance_wei']) > 0) {
            throw new RuntimeException('pilot_budget_or_balance_bounds_invalid');
        }

        return $values;
    }

    public static function integer(mixed $value): string
    {
        if ((! is_int($value) && ! is_string($value))
            || preg_match('/\A[1-9][0-9]{0,77}\z/', (string) $value) !== 1) {
            throw new RuntimeException('pilot_positive_integer_required');
        }

        return (string) $value;
    }

    public static function kaiaToWei(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A(0|[1-9][0-9]{0,59})(\.[0-9]{1,18})?\z/', $value) !== 1) {
            throw new RuntimeException('pilot_exact_decimal_required');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return bcadd(bcmul($whole, '1000000000000000000', 0), str_pad($fraction, 18, '0'), 0);
    }

    public static function weiToKaia(string $wei): string
    {
        $padded = str_pad($wei, 19, '0', STR_PAD_LEFT);
        $fraction = rtrim(substr($padded, -18), '0');

        return substr($padded, 0, -18).($fraction === '' ? '' : '.'.$fraction);
    }
}
