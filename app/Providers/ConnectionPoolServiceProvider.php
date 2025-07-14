<?php

namespace App\Providers;

use App\Services\ConnectionPoolManager;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;

class ConnectionPoolServiceProvider extends ServiceProvider
{
    private static bool $shutdownRegistered = false;

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
        // Initialize connection pool on boot (only once)
        ConnectionPoolManager::init([
            'max_connections_per_pool' => config('gps.pool.max_connections', 20),
            'connection_timeout' => config('gps.pool.connection_timeout', 300),
            'idle_timeout' => config('gps.pool.idle_timeout', 120), // Increased
            'connect_timeout' => config('gps.pool.connect_timeout', 5),
            'socket_timeout' => config('gps.pool.socket_timeout', 3)
        ]);

        // Register shutdown ONLY ONCE
        if (!self::$shutdownRegistered) {
            $this->setupGracefulShutdown();
            self::$shutdownRegistered = true;
        }
    }

    /**
     * Setup graceful shutdown of connection pools (ONCE only)
     */
    private function setupGracefulShutdown(): void
    {
        register_shutdown_function(function () {
            static $shutdownCalled = false;
            
            if ($shutdownCalled) {
                return; // Prevent multiple shutdowns
            }
            $shutdownCalled = true;
            
            try {
                ConnectionPoolManager::shutdown();
            } catch (\Exception $e) {
                Log::channel('gps_pool')->error('Shutdown failed: ' . $e->getMessage());
            }
        });
    }
}