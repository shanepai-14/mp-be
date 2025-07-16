<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\SocketPool\Client\SocketPoolClient;

class SocketPoolServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(SocketPoolClient::class, function ($app) {
            return new SocketPoolClient(
                config('socket_pool.socket_path'),
                config('socket_pool.timeout')
            );
        });

        // Register alias
        $this->app->alias(SocketPoolClient::class, 'socket-pool');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__ . '/../../config/socket_pool.php' => config_path('socket_pool.php'),
        ], 'socket-pool-config');

        // Register health check
        if ($this->app->environment('production')) {
            $this->registerHealthCheck();
        }
    }

    /**
     * Register health check for the socket pool service
     */
    private function registerHealthCheck(): void
    {
        $this->app->booted(function () {
            $client = $this->app->make(SocketPoolClient::class);
            
            if (!$client->isServiceRunning()) {
                \Log::warning('Socket Pool Service is not running');
            }
        });
    }
}