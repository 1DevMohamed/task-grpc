<?php

namespace App\Providers;

use App\Grpc\Vendor\VendorServiceInterface;
use App\Services\VendorGrpcHandlerService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            VendorServiceInterface::class,
            VendorGrpcHandlerService::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {

    }
}