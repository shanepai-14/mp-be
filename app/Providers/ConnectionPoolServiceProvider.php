<?php

namespace App\Providers;

use App\Services\ConnectionPoolManager;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;

class ConnectionPoolServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton('connection.pool', function ($app) {
            return new ConnectionPoolManager();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Initialize connection pool on boot
        ConnectionPoolManager::init([
            'max_connections_per_pool' => config('gps.pool.max_connections', 20),
            'connection_timeout' => config('gps.pool.connection_timeout', 300),
            'idle_timeout' => config('gps.pool.idle_timeout', 60),
            'connect_timeout' => config('gps.pool.connect_timeout', 3),
            'socket_timeout' => config('gps.pool.socket_timeout', 2)
        ]);

        // Setup periodic cleanup
        $this->setupPeriodicCleanup();
        
        // Setup graceful shutdown
        $this->setupGracefulShutdown();
    }

    /**
     * Setup periodic cleanup of connection pools
     */
    private function setupPeriodicCleanup(): void
    {
        // Only run in web context (not CLI)
        if (!$this->app->runningInConsole()) {
            register_shutdown_function(function () {
                try {
                    $stats = ConnectionPoolManager::cleanup();
                    if ($stats['connections_removed'] > 0) {
                        Log::channel('gps_pool')->info('Periodic cleanup completed', $stats);
                    }
                } catch (\Exception $e) {
                    Log::channel('gps_pool')->error('Cleanup failed: ' . $e->getMessage());
                }
            });
        }
    }

    /**
     * Setup graceful shutdown of connection pools
     */
    private function setupGracefulShutdown(): void
    {
        register_shutdown_function(function () {
            try {
                ConnectionPoolManager::shutdown();
            } catch (\Exception $e) {
                Log::channel('gps_pool')->error('Shutdown failed: ' . $e->getMessage());
            }
        });
    }
}