<?php

namespace App\Providers;

use App\Services\DataProvider\DummyProductDataProvider;
use App\Services\DataProvider\ProductDataProviderInterface;
use App\Services\DataProvider\ShopwareProductDataProvider;
use App\Services\Translation\ChatGPTTranslator;
use App\Services\Translation\MockTranslator;
use App\Services\Translation\TranslatorInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // SHOPWARE DI - Configured with values from config
        $this->app->bind(
            ProductDataProviderInterface::class,
            fn () => new ShopwareProductDataProvider(
                baseUrl: rtrim((string) config('services.shopware.url'), '/'),
                token: (string) config('services.shopware.token'),
            )
        );

        // DUMMY DI (disabled for Shopware testing)
        // $this->app->bind(
        //     ProductDataProviderInterface::class,
        //     DummyProductDataProvider::class
        // );

        // Mock Translator DI (disabled)
        // $this->app->bind(
        //     TranslatorInterface::class,
        //     MockTranslator::class
        // );

        // ChatGPT Translator DI
        $this->app->bind(
            TranslatorInterface::class,
            fn () => new ChatGPTTranslator(
                apiKey: (string) config('services.openai.api_key'),
                model: (string) config('services.openai.model', 'gpt-4o-mini'),
            )
        );
    }
}
