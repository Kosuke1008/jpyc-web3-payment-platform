<?php

namespace App\Console\Commands;

use App\Payments\MainnetPilotGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Throwable;

final class RotateMainnetPilotStaffPin extends Command
{
    protected $signature = 'mainnet:rotate-pilot-staff-pin
        {--secret-file= : Absolute path for one-time PIN delivery}';

    protected $description = 'Rotate only the dedicated Mainnet pilot Staff PIN and revoke its API tokens';

    public function handle(MainnetPilotGuard $guard): int
    {
        $secretFile = $this->option('secret-file');
        $secretWritten = false;

        try {
            $secretFile = $this->validatedSecretFile($secretFile);
            $guard->assertDatabase();
            $guard->profile();
            $identities = $guard->identities();
            $storeId = $identities['store']->id;
            $staffId = $identities['staff']->id;

            if (! $this->input->isInteractive()
                || ! $this->confirm(
                    "Rotate only the Staff PIN for pilot Store {$storeId} / Staff {$staffId}?",
                    false
                )) {
                $this->line('Cancelled; no credentials changed.');

                return self::FAILURE;
            }

            $staffPin = bin2hex(random_bytes(16));
            $this->writeSecret($secretFile, $staffPin);
            $secretWritten = true;

            $guard->transaction(function () use (
                $guard,
                $storeId,
                $staffId,
                $staffPin
            ): void {
                $guard->profile();
                $identities = $guard->identities(lock: true);
                if ($identities['store']->id !== $storeId
                    || $identities['staff']->id !== $staffId) {
                    throw new RuntimeException('pilot_confirmation_target_changed');
                }

                $identities['staff']->update([
                    'pin' => Hash::make($staffPin),
                ]);
                $identities['staff']->tokens()->delete();
            });
        } catch (Throwable) {
            if ($secretWritten && is_string($secretFile)) {
                @unlink($secretFile);
            }
            $this->error(
                'NOT_READY: Staff PIN rotation refused or rolled back; verify the pilot environment, identities, and secret-file path.'
            );

            return self::FAILURE;
        }

        $this->info('Mainnet staging pilot Staff PIN rotated; existing Staff API tokens were revoked.');
        $this->line('The new PIN was written once to the requested mode-600 secret file.');

        return self::SUCCESS;
    }

    private function validatedSecretFile(mixed $path): string
    {
        if (! is_string($path)
            || $path === ''
            || ! str_starts_with($path, '/')
            || str_contains($path, "\0")
            || file_exists($path)
            || is_link($path)) {
            throw new RuntimeException('invalid_secret_file');
        }

        $directory = realpath(dirname($path));
        if ($directory === false || ! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException('invalid_secret_file_directory');
        }

        return $directory.DIRECTORY_SEPARATOR.basename($path);
    }

    private function writeSecret(string $path, string $staffPin): void
    {
        $previousUmask = umask(0077);
        try {
            $handle = fopen($path, 'x');
        } finally {
            umask($previousUmask);
        }

        if ($handle === false) {
            throw new RuntimeException('secret_file_create_failed');
        }

        try {
            if (! chmod($path, 0600)
                || fwrite($handle, $staffPin.PHP_EOL) !== strlen($staffPin) + 1
                || ! fflush($handle)) {
                throw new RuntimeException('secret_file_write_failed');
            }
        } finally {
            fclose($handle);
        }
    }
}
