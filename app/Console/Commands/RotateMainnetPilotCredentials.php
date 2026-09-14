<?php

namespace App\Console\Commands;

use App\Payments\MainnetPilotGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Throwable;

final class RotateMainnetPilotCredentials extends Command
{
    protected $signature = 'mainnet:rotate-pilot-credentials';

    protected $description = 'Interactively rotate only the dedicated pilot Store/Staff/User credential hashes';

    public function handle(MainnetPilotGuard $guard): int
    {
        try {
            $guard->assertDatabase();
            $guard->profile();
            $identities = $guard->identities();
            $storeId = $identities['store']->id;
            $userId = $identities['user']->id;
            $staffId = $identities['staff']->id;
            if (! $this->input->isInteractive()
                || ! $this->confirm("Rotate credentials for pilot Store {$storeId} / User {$userId}?", false)) {
                $this->line('Cancelled; no credentials changed.');

                return self::FAILURE;
            }
            $credentials = $guard->transaction(function () use ($guard, $storeId, $staffId, $userId): array {
                $guard->profile();
                $identities = $guard->identities(lock: true);
                if ($identities['store']->id !== $storeId || $identities['staff']->id !== $staffId || $identities['user']->id !== $userId) {
                    throw new RuntimeException('pilot_confirmation_target_changed');
                }
                $credentials = [
                    'store_pin' => bin2hex(random_bytes(16)),
                    'staff_pin' => bin2hex(random_bytes(16)),
                    'user_password' => bin2hex(random_bytes(16)),
                ];
                $identities['store']->update(['store_pin' => Hash::make($credentials['store_pin'])]);
                $identities['staff']->update(['pin' => Hash::make($credentials['staff_pin'])]);
                $identities['user']->update(['password' => Hash::make($credentials['user_password'])]);

                return $credentials;
            });
        } catch (Throwable) {
            $this->error('NOT_READY: rotation refused or rolled back; verify pilot environment and identities.');

            return self::FAILURE;
        }
        $this->warn('SECRETS — shown once. Store securely; do not record terminal output or paste into chat.');
        foreach ($credentials as $key => $value) {
            $this->line($key.'='.$value);
        }

        return self::SUCCESS;
    }
}
