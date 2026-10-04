<?php

namespace App\Providers;

use App\Blockchain\NetworkProfileRegistry;
use App\Services\Payments\ConfiguredFeeDelegationGateway;
use App\Services\Payments\FeeDelegatedTransactionInspector;
use App\Services\Payments\FeeDelegationGateway;
use App\Services\Payments\KaiaFeeDelegatedTransactionInspector;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(NetworkProfileRegistry::class);

        $this->app->bind(
            FeeDelegatedTransactionInspector::class,
            KaiaFeeDelegatedTransactionInspector::class
        );
        $this->app->bind(
            FeeDelegationGateway::class,
            ConfiguredFeeDelegationGateway::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('user-authentication', function (Request $request) {
            $identity = hash(
                'sha256',
                mb_strtolower(trim((string) $request->input('email')))
            );

            return $this->failedAuthenticationLimits(
                $request,
                $identity,
                20
            );
        });

        RateLimiter::for('staff-authentication', function (Request $request) {
            $identity = hash('sha256', json_encode([
                mb_strtolower(trim((string) $request->input('store_code'))),
                mb_strtolower(trim((string) $request->input('staff_id'))),
            ], JSON_THROW_ON_ERROR));

            return $this->failedAuthenticationLimits(
                $request,
                $identity,
                10
            );
        });

        // Resolve once during bootstrap so malformed or unsupported network
        // configuration cannot reach payment business logic.
        $this->app->make(NetworkProfileRegistry::class)->active();
    }

    /**
     * @return array<int, Limit>
     */
    private function failedAuthenticationLimits(
        Request $request,
        string $identity,
        int $identityAttempts
    ): array {
        $failed = static fn ($response): bool => $response->getStatusCode() === 401;
        $ip = $request->ip() ?? 'unknown';

        return [
            Limit::perMinute(30)
                ->by("ip:{$ip}")
                ->after($failed),
            Limit::perMinute(5)
                ->by("identity-ip:{$identity}:{$ip}")
                ->after($failed),
            Limit::perMinutes(10, $identityAttempts)
                ->by("identity:{$identity}")
                ->after($failed),
        ];
    }
}
