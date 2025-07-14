<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ConnectionPoolManager
{
    private static array $pools = [];
    private static int $maxConnectionsPerPool = 10;
    private static int $connectionTimeout = 300; // Increased to 5 minutes
    private static int $idleTimeout = 120; // Increased to 2 minutes
    private static array $config = [];
    private static bool $initialized = false;
    private static int $lastCleanup = 0;

    /**
     * Initialize the connection pool with configuration
     */
    public static function init(array $config = []): void
    {
        if (self::$initialized) {
            return; // Prevent multiple initializations
        }

        self::$config = array_merge([
            'max_connections_per_pool' => 10,
            'connection_timeout' => 300,
            'idle_timeout' => 120,
            'connect_timeout' => 5,
            'socket_timeout' => 3
        ], $config);

        self::$maxConnectionsPerPool = self::$config['max_connections_per_pool'];
        self::$connectionTimeout = self::$config['connection_timeout'];
        self::$idleTimeout = self::$config['idle_timeout'];
        self::$lastCleanup = time();
        self::$initialized = true;

        Log::channel('gps_pool')->info('Connection pool initialized', [
            'max_connections_per_pool' => self::$maxConnectionsPerPool,
            'connection_timeout' => self::$connectionTimeout,
            'idle_timeout' => self::$idleTimeout
        ]);
    }

    /**
     * Get a connection from the pool or create a new one
     */
    public static function getConnection(string $host, int $port): ?array
    {
        if (!self::$initialized) {
            self::init(); // Auto-initialize if not done
        }

        $poolKey = self::getPoolKey($host, $port);
        
        // Initialize pool if it doesn't exist
        if (!isset(self::$pools[$poolKey])) {
            self::$pools[$poolKey] = [
                'connections' => [],
                'host' => $host,
                'port' => $port,
                'created_at' => time(),
                'stats' => [
                    'total_created' => 0,
                    'total_reused' => 0,
                    'active_count' => 0,
                    'failed_connections' => 0
                ]
            ];
        }

        $pool = &self::$pools[$poolKey];
        
        // Throttled cleanup - only every 60 seconds per pool
        $now = time();
        if (($now - self::$lastCleanup) > 60) {
            self::gentleCleanup($poolKey);
            self::$lastCleanup = $now;
        }
        
        // Try to get an available connection from the pool
        $connection = self::getAvailableConnection($pool);
        
        if ($connection) {
            $pool['stats']['total_reused']++;
            $connection['last_used'] = time();
            $connection['in_use'] = true;
            
            Log::channel('gps_pool')->debug("Reusing connection for {$poolKey}", [
                'connection_id' => $connection['id'],
                'pool_size' => count($pool['connections'])
            ]);
            
            return $connection;
        }
        
        // Create new connection if pool has space
        if (count($pool['connections']) < self::$maxConnectionsPerPool) {
            $connection = self::createNewConnection($host, $port);
            
            if ($connection) {
                $connection['id'] = uniqid();
                $connection['pool_key'] = $poolKey;
                $connection['created_at'] = time();
                $connection['last_used'] = time();
                $connection['in_use'] = true;
                $connection['use_count'] = 0;
                $connection['validated_at'] = time();
                
                $pool['connections'][] = $connection;
                $pool['stats']['total_created']++;
                $pool['stats']['active_count']++;
                
                Log::channel('gps_pool')->info("Created new connection for {$poolKey}", [
                    'connection_id' => $connection['id'],
                    'pool_size' => count($pool['connections'])
                ]);
                
                return $connection;
            } else {
                $pool['stats']['failed_connections']++;
                Log::channel('gps_pool')->warning("Failed to create connection for {$poolKey}");
            }
        }
        
        Log::channel('gps_pool')->warning("No available connections for {$poolKey}", [
            'pool_size' => count($pool['connections']),
            'max_size' => self::$maxConnectionsPerPool,
            'failed_connections' => $pool['stats']['failed_connections']
        ]);
        
        return null;
    }

    /**
     * Return a connection to the pool
     */
    public static function returnConnection(array $connection): void
    {
        if (!isset($connection['pool_key']) || !isset(self::$pools[$connection['pool_key']])) {
            return;
        }

        $poolKey = $connection['pool_key'];
        $pool = &self::$pools[$poolKey];

        // Find and update the connection in the pool
        foreach ($pool['connections'] as &$poolConnection) {
            if ($poolConnection['id'] === $connection['id']) {
                $poolConnection['in_use'] = false;
                $poolConnection['last_used'] = time();
                $poolConnection['use_count']++;
                $pool['stats']['active_count'] = max(0, $pool['stats']['active_count'] - 1);
                
                Log::channel('gps_pool')->debug("Returned connection {$connection['id']} to pool {$poolKey}");
                break;
            }
        }
    }

    /**
     * Send data through a pooled connection
     */
    public static function sendData(string $host, int $port, string $data, string $vehicleId): array
    {
        $connection = self::getConnection($host, $port);
        
        if (!$connection) {
            return [
                'success' => false,
                'error' => 'No available connections',
                'vehicle_id' => $vehicleId
            ];
        }

        try {
            $result = self::writeAndRead($connection, $data, $vehicleId);
            self::returnConnection($connection);
            return $result;
            
        } catch (\Exception $e) {
            // Mark connection as failed but don't remove immediately
            self::markConnectionAsFailed($connection);
            self::returnConnection($connection);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'vehicle_id' => $vehicleId
            ];
        }
    }

    /**
     * Mark connection as failed (for gentle cleanup later)
     */
    private static function markConnectionAsFailed(array $connection): void
    {
        if (!isset($connection['pool_key']) || !isset(self::$pools[$connection['pool_key']])) {
            return;
        }

        $poolKey = $connection['pool_key'];
        $pool = &self::$pools[$poolKey];

        foreach ($pool['connections'] as &$poolConnection) {
            if ($poolConnection['id'] === $connection['id']) {
                $poolConnection['failed_at'] = time();
                $poolConnection['failure_count'] = ($poolConnection['failure_count'] ?? 0) + 1;
                break;
            }
        }
    }

    /**
     * Write data to connection and read response
     */
    private static function writeAndRead(array $connection, string $data, string $vehicleId): array
    {
        $socket = $connection['socket'];
        $message = $data . "\r";
        
        // Write data
        $bytesWritten = socket_write($socket, $message, strlen($message));
        
        if ($bytesWritten === false) {
            throw new \Exception("Failed to write data: " . socket_strerror(socket_last_error($socket)));
        }
        
        if ($bytesWritten === 0) {
            throw new \Exception("No bytes written to socket");
        }

        // Read response (with timeout) - make this optional
        $response = '';
        $startTime = microtime(true);
        
        // Set non-blocking mode for reading
        socket_set_nonblock($socket);
        
        // Try to read response for up to 1 second
        while ((microtime(true) - $startTime) < 1.0) {
            $response = socket_read($socket, 2048);
            
            if ($response !== false && $response !== '') {
                break;
            }
            
            if ($response === false) {
                $error = socket_last_error($socket);
                if ($error !== SOCKET_EAGAIN && $error !== SOCKET_EWOULDBLOCK) {
                    // Real error occurred
                    socket_set_block($socket);
                    throw new \Exception("Failed to read response: " . socket_strerror($error));
                }
            }
            
            usleep(10000); // Wait 10ms before trying again
        }
        
        // Set back to blocking mode
        socket_set_block($socket);

        return [
            'success' => true,
            'response' => trim($response),
            'bytes_written' => $bytesWritten,
            'vehicle_id' => $vehicleId,
            'connection_id' => $connection['id']
        ];
    }

    /**
     * Get an available connection from the pool
     */
    private static function getAvailableConnection(array &$pool): ?array
    {
        foreach ($pool['connections'] as &$connection) {
            if (!$connection['in_use'] && self::isConnectionHealthy($connection)) {
                return $connection;
            }
        }
        
        return null;
    }

    /**
     * Create a new TCP connection with better error handling
     */
    private static function createNewConnection(string $host, int $port): ?array
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
        if ($socket === false) {
            Log::channel('gps_pool')->error("Failed to create socket: " . socket_strerror(socket_last_error()));
            return null;
        }

        // Set socket options for optimal performance
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);
        
        // More generous timeouts
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, [
            "sec" => self::$config['socket_timeout'], 
            "usec" => 0
        ]);
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, [
            "sec" => self::$config['socket_timeout'], 
            "usec" => 0
        ]);
        
        // Set TCP_NODELAY to reduce latency
        socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
        
        // Try to connect
        if (@socket_connect($socket, $host, $port)) {
            Log::channel('gps_pool')->debug("Successfully connected to {$host}:{$port}");
            
            return [
                'socket' => $socket,
                'host' => $host,
                'port' => $port
            ];
        }
        
        $error = socket_last_error($socket);
        socket_close($socket);
        
        Log::channel('gps_pool')->warning("Failed to connect to {$host}:{$port}: " . 
            socket_strerror($error) . " [{$error}]");
        
        return null;
    }

    /**
     * Check if connection is healthy (less aggressive)
     */
    private static function isConnectionHealthy(array $connection): bool
    {
        $socket = $connection['socket'];
        
        if (!is_resource($socket)) {
            return false;
        }
        
        // Check if connection has too many recent failures
        if (isset($connection['failure_count']) && $connection['failure_count'] > 3) {
            return false;
        }
        
        // Check socket error state
        $error = socket_get_option($socket, SOL_SOCKET, SO_ERROR);
        if ($error !== 0) {
            return false;
        }
        
        // More generous timeouts
        $now = time();
        if (($now - $connection['created_at']) > self::$connectionTimeout) {
            return false;
        }
        
        if (isset($connection['last_used']) && 
            ($now - $connection['last_used']) > self::$idleTimeout) {
            return false;
        }
        
        return true;
    }

    /**
     * Gentle cleanup - only remove clearly dead connections
     */
    private static function gentleCleanup(string $poolKey): void
    {
        if (!isset(self::$pools[$poolKey])) {
            return;
        }

        $pool = &self::$pools[$poolKey];
        $initialCount = count($pool['connections']);
        $removed = 0;
        
        $pool['connections'] = array_filter($pool['connections'], function($connection) use (&$removed) {
            // Only remove connections that are clearly dead
            if (!self::isConnectionHealthy($connection)) {
                if (isset($connection['socket']) && is_resource($connection['socket'])) {
                    socket_close($connection['socket']);
                }
                $removed++;
                return false;
            }
            return true;
        });
        
        // Reindex array
        $pool['connections'] = array_values($pool['connections']);
        
        if ($removed > 0) {
            Log::channel('gps_pool')->info("Gentle cleanup for pool {$poolKey}: removed {$removed} connections", [
                'before' => $initialCount,
                'after' => count($pool['connections'])
            ]);
        }
    }

    /**
     * Clean up old and dead connections (less aggressive)
     */
    public static function cleanup(): array
    {
        $stats = [
            'pools_cleaned' => 0,
            'connections_removed' => 0,
            'total_pools' => count(self::$pools)
        ];

        foreach (self::$pools as $poolKey => $pool) {
            $initialCount = count($pool['connections']);
            self::gentleCleanup($poolKey);
            $finalCount = count(self::$pools[$poolKey]['connections']);
            
            $removed = $initialCount - $finalCount;
            if ($removed > 0) {
                $stats['pools_cleaned']++;
                $stats['connections_removed'] += $removed;
            }
        }

        return $stats;
    }

    /**
     * Get pool statistics
     */
    public static function getStats(): array
    {
        $totalConnections = 0;
        $totalActive = 0;
        $poolStats = [];

        foreach (self::$pools as $poolKey => $pool) {
            $activeCount = count(array_filter($pool['connections'], fn($conn) => $conn['in_use']));
            $totalConnections += count($pool['connections']);
            $totalActive += $activeCount;
            
            $poolStats[$poolKey] = [
                'total_connections' => count($pool['connections']),
                'active_connections' => $activeCount,
                'idle_connections' => count($pool['connections']) - $activeCount,
                'total_created' => $pool['stats']['total_created'],
                'total_reused' => $pool['stats']['total_reused'],
                'failed_connections' => $pool['stats']['failed_connections'],
                'reuse_ratio' => $pool['stats']['total_created'] > 0 ? 
                    round($pool['stats']['total_reused'] / $pool['stats']['total_created'], 2) : 0
            ];
        }

        return [
            'total_pools' => count(self::$pools),
            'total_connections' => $totalConnections,
            'total_active' => $totalActive,
            'total_idle' => $totalConnections - $totalActive,
            'max_connections_per_pool' => self::$maxConnectionsPerPool,
            'pools' => $poolStats
        ];
    }

    /**
     * Close all connections and clear pools
     */
    public static function shutdown(): void
    {
        foreach (self::$pools as $poolKey => $pool) {
            foreach ($pool['connections'] as $connection) {
                if (isset($connection['socket']) && is_resource($connection['socket'])) {
                    socket_close($connection['socket']);
                }
            }
        }
        
        self::$pools = [];
        Log::channel('gps_pool')->info("Connection pool manager shutdown complete");
    }

    /**
     * Generate pool key from host and port
     */
    private static function getPoolKey(string $host, int $port): string
    {
        return "{$host}:{$port}";
    }
}