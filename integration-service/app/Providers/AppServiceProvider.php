<?php

namespace App\Providers;

use App\Handlers\UpdatePriceHandler;
use App\Handlers\UpdateStockHandler;
use App\Integration\Dispatcher\EventHandlerRegistry;
use App\Integration\Dispatcher\MessageDispatcher;
use App\Integration\Exceptions\ErrorClassifier;
use App\Integration\Logging\IntegrationLogger;
use App\Integration\Metrics\MetricsCollector;
use App\Integration\Repositories\InboxRepository;
use App\Integration\Retry\RetryPolicy;
use App\Integration\Services\EventProcessingService;
use App\Integration\Validation\EventValidator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton services — shared across all workers in this process
        $this->app->singleton(IntegrationLogger::class);
        $this->app->singleton(MetricsCollector::class);
        $this->app->singleton(ErrorClassifier::class);
        $this->app->singleton(EventValidator::class);
        $this->app->singleton(InboxRepository::class);
        $this->app->singleton(RetryPolicy::class);

        // Event Handler Registry — register all handlers here
        $this->app->singleton(EventHandlerRegistry::class, function ($app) {
            $registry = new EventHandlerRegistry($app);

            // ──────────────────────────────────────────────────
            // Register event handlers here.
            // To add a new event, add one line:
            //   $registry->register(NewHandler::class, NewHandler::supportedEventTypes());
            // ──────────────────────────────────────────────────

            $registry->register(UpdatePriceHandler::class, UpdatePriceHandler::supportedEventTypes());
            $registry->register(UpdateStockHandler::class, UpdateStockHandler::supportedEventTypes());

            return $registry;
        });

        // Message Dispatcher
        $this->app->singleton(MessageDispatcher::class);

        // Event Processing Service (the core pipeline)
        $this->app->singleton(EventProcessingService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
