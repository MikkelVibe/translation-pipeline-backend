<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\DataProvider\ProductDataProviderInterface;
// use App\Services\DataProvider\ShopwareProductDataProvider;
use App\Services\DataProvider\DummyProductDataProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        # Dependency Injections
        
        // SHOPWARE DI
        // $this->app->bind(
        // ProductDataProviderInterface::class,
        // ShopwareProductDataProvider::class,
        // );
        
        // DUMMY DI
        $this->app->bind(
            ProductDataProviderInterface::class, 
            DummyProductDataProvider::class
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
