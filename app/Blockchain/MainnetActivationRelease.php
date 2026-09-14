<?php

namespace App\Blockchain;

final class MainnetActivationRelease
{
    public const RELEASE_ID = 'phase-13-mainnet-pilot-v1';

    public static function capable(): bool
    {
        $path = base_path('bootstrap/cache/mainnet-pilot-activation.php');
        if (! is_file($path)) {
            return false;
        }

        $manifest = require $path;

        return is_array($manifest)
            && array_keys($manifest) === ['release']
            && $manifest['release'] === self::RELEASE_ID;
    }
}
