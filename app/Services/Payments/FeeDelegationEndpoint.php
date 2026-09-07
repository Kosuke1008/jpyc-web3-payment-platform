<?php

namespace App\Services\Payments;

class FeeDelegationEndpoint
{
    public static function isManaged(mixed $url, mixed $apiKey): bool
    {
        return self::isAllowed($url, $apiKey)
            && is_string($url)
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && is_string($apiKey)
            && $apiKey !== '';
    }

    public static function isAllowed(mixed $url, mixed $apiKey): bool
    {
        if (! is_string($url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || (! is_null($apiKey) && ! is_string($apiKey))
            || (is_string($apiKey)
                && (strlen($apiKey) > 4096
                    || preg_match('/\s/', $apiKey) === 1))) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts)
            || ! is_string($parts['scheme'] ?? null)
            || ! is_string($parts['host'] ?? null)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '/')) {
            return false;
        }

        if ($parts['scheme'] === 'https') {
            return true;
        }

        return $parts['scheme'] === 'http'
            && $parts['host'] === '127.0.0.1'
            && is_string($apiKey)
            && $apiKey !== ''
            && strlen($apiKey) <= 4096
            && preg_match('/\s/', $apiKey) !== 1;
    }
}
