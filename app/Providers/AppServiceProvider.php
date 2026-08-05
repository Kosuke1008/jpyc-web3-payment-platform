<?php

namespace App\Providers;

use App\Services\Payments\FeeDelegatedTransactionInspector;
use App\Services\Payments\FeeDelegationGateway;
use App\Services\Payments\KaiaFeeDelegatedTransactionInspector;
use App\Services\Payments\KaiaFeeDelegationGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            FeeDelegatedTransactionInspector::class,
            KaiaFeeDelegatedTransactionInspector::class
        );
        $this->app->bind(
            FeeDelegationGateway::class,
            KaiaFeeDelegationGateway::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
