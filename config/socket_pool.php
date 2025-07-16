<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Socket Pool Service Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Socket Pool Service client integration
    |
    */

    // Basic connection settings
    'socket_path' => env('SOCKET_POOL_UNIX_PATH', '/tmp/socket_pool_service.sock'),
    'timeout' => env('SOCKET_POOL_TIMEOUT', 5),

    // Pool configuration
    'max_pool_size' => env('SOCKET_POOL_MAX_SIZE', 100),
    'connection_timeout' => env('SOCKET_POOL_CONNECTION_TIMEOUT', 30),
    'max_retries' => env('SOCKET_POOL_MAX_RETRIES', 3),

    // Client configuration
    'cache_enabled' => env('SOCKET_POOL_CACHE_ENABLED', true),
    'cache_ttl' => env('SOCKET_POOL_CACHE_TTL', 300),
    'retry_attempts' => env('SOCKET_POOL_RETRY_ATTEMPTS', 3),
    'retry_delay' => env('SOCKET_POOL_RETRY_DELAY', 100), // milliseconds

    // Circuit breaker configuration
    'circuit_breaker_enabled' => env('SOCKET_POOL_CIRCUIT_BREAKER', true),
    'circuit_breaker_threshold' => env('SOCKET_POOL_CB_THRESHOLD', 5),
    'circuit_breaker_timeout' => env('SOCKET_POOL_CB_TIMEOUT', 60),

    // Metrics and monitoring
    'metrics_enabled' => env('SOCKET_POOL_METRICS_ENABLED', true),
    'health_check_interval' => env('SOCKET_POOL_HEALTH_INTERVAL', 60),

    // Logging
    'log_level' => env('SOCKET_POOL_LOG_LEVEL', 'INFO'),
    'log_channel' => env('SOCKET_POOL_LOG_CHANNEL', 'stack'),

    // Default GPS server settings (can be overridden per request)
    'default_gps_servers' => [
        'primary' => [
            'host' => env('GPS_PRIMARY_HOST', 'localhost'),
            'port' => env('GPS_PRIMARY_PORT', 2199),
        ],
        'backup' => [
            'host' => env('GPS_BACKUP_HOST', 'localhost'),
            'port' => env('GPS_BACKUP_PORT', 2200),
        ],
    ],

    // Batch processing settings
    'batch_size' => env('SOCKET_POOL_BATCH_SIZE', 50),
    'batch_timeout' => env('SOCKET_POOL_BATCH_TIMEOUT', 30),

    // Performance tuning
    'enable_async' => env('SOCKET_POOL_ENABLE_ASYNC', false),
    'max_concurrent_requests' => env('SOCKET_POOL_MAX_CONCURRENT', 10),

    // Security settings
    'auth_enabled' => env('SOCKET_POOL_AUTH_ENABLED', false),
    'auth_token' => env('SOCKET_POOL_AUTH_TOKEN', ''),
    'ssl_enabled' => env('SOCKET_POOL_SSL_ENABLED', false),
    'ssl_cert_path' => env('SOCKET_POOL_SSL_CERT_PATH', ''),
    'ssl_key_path' => env('SOCKET_POOL_SSL_KEY_PATH', ''),
];