<?php

namespace QuetzalStudio\RequestLogger;

use Illuminate\Support\ServiceProvider;
use QuetzalStudio\RequestLogger\Http\Middleware\RequestLogger as RequestLoggerMiddleware;

class RequestLoggerServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/request_logger.php', 'request_logger');

        $this->app->singleton(RequestLoggerMiddleware::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/request_logger.php' => config_path('request_logger.php'),
            ], 'request-logger-config');
        }
    }
}
