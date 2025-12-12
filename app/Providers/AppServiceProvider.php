<?php

namespace App\Providers;

use App\Services\DataProvider\DummyProductDataProvider;
use App\Services\DataProvider\ProductDataProviderInterface;
use App\Services\DataProvider\ShopwareProductDataProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Dependency Injections

        // SHOPWARE DI - Configured with values from config
        $this->app->bind(
            ProductDataProviderInterface::class,
            fn() => new ShopwareProductDataProvider(
                baseUrl: rtrim((string) config('services.shopware.url'), '/'),
                token: (string) config('services.shopware.token'),
            )
        );

        // DUMMY DI (disabled for Shopware testing)
        // $this->app->bind(
        //     ProductDataProviderInterface::class,
        //     DummyProductDataProvider::class
        // );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
