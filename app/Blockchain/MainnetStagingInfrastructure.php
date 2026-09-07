<?php

namespace App\Blockchain;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MainnetStagingInfrastructure
{
    /** @return array{ready: bool, name: ?string, migrations: list<string>} */
    public function database(): array
    {
        try {
            DB::connection()->getPdo();
            $migrations = Schema::hasTable('migrations')
                ? DB::table('migrations')->pluck('migration')->all()
                : [];

            return [
                'ready' => true,
                'name' => DB::connection()->getDatabaseName(),
                'migrations' => array_values(array_filter(
                    $migrations,
                    'is_string'
                )),
            ];
        } catch (Throwable) {
            return ['ready' => false, 'name' => null, 'migrations' => []];
        }
    }

    /** @return array{ready: bool, driver: string, prefix: string} */
    public function cacheLock(): array
    {
        $driver = (string) config('cache.default');
        $prefix = (string) config('cache.prefix');

        if (! in_array($driver, ['database', 'redis'], true)) {
            return ['ready' => false, 'driver' => $driver, 'prefix' => $prefix];
        }

        try {
            $lock = Cache::lock(
                'mainnet-staging:readiness-lock-probe',
                5
            );
            if (! $lock->get()) {
                return ['ready' => false, 'driver' => $driver, 'prefix' => $prefix];
            }
            $lock->release();

            return ['ready' => true, 'driver' => $driver, 'prefix' => $prefix];
        } catch (Throwable) {
            return ['ready' => false, 'driver' => $driver, 'prefix' => $prefix];
        }
    }
}
