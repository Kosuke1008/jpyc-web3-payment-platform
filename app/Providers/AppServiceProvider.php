<?php

namespace App\Providers;

use App\Blockchain\NetworkProfileRegistry;
use App\Services\Payments\ConfiguredFeeDelegationGateway;
use App\Services\Payments\FeeDelegatedTransactionInspector;
use App\Services\Payments\FeeDelegationGateway;
use App\Services\Payments\KaiaFeeDelegatedTransactionInspector;
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
        // Resolve once during bootstrap so malformed or unsupported network
        // configuration cannot reach payment business logic.
        $this->app->make(NetworkProfileRegistry::class)->active();
    }
}
