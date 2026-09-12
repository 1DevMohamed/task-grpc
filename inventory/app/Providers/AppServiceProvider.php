<?php

namespace App\Providers;
use App\Grpc\Inventory\InventoryServiceInterface;
use App\Services\InventoryService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
         $this->app->bind(
            InventoryServiceInterface::class,
            InventoryService::class
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
