<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ConnectionPoolManager
{
    private static array $pools = [];
    private static int $maxConnectionsPerPool = 10;
    private static int $connectionTimeout = 60; // seconds
    private static int $idleTimeout = 30; // seconds
    private static array $config = [];

    /**
     * Initialize the connection pool with configuration
     */
    public static function init(array $config = []): void
    {
        self::$config = array_merge([
            'max_connections_per_pool' => 10,
            'connection_timeout' => 60,
            'idle_timeout' => 30,
            'connect_timeout' => 5,
            'socket_timeout' => 2
        ], $config);

        self::$maxConnectionsPerPool = self::$config['max_connections_per_pool'];
        self::$connectionTimeout = self::$config['connection_timeout'];
        self::$idleTimeout = self::$config['idle_timeout'];
    }

    /**
     * Get a connection from the pool or create a new one
     */
    public static function getConnection(string $host, int $port): ?array
    {
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
                    'active_count' => 0
                ]
            ];
        }

        $pool = &self::$pools[$poolKey];
        
        // Try to get an available connection from the pool
        $connection = self::getAvailableConnection($pool);
        
        if ($connection) {
            $pool['stats']['total_reused']++;
            $connection['last_used'] = time();
            $connection['in_use'] = true;
            
            
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
                
                $pool['connections'][] = $connection;
                $pool['stats']['total_created']++;
                $pool['stats']['active_count']++;
                
                return $connection;
            }
        }
        
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
            // Connection failed, remove it from pool
            self::removeConnection($connection);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'vehicle_id' => $vehicleId
            ];
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

        // Read response (with timeout)
        $response = socket_read($socket, 2048);
        
        if ($response === false) {
            $error = socket_strerror(socket_last_error($socket));
            if (socket_last_error($socket) !== SOCKET_EAGAIN) {
                throw new \Exception("Failed to read response: " . $error);
            }
            $response = ''; // Timeout is acceptable
        }

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
            if (!$connection['in_use'] && self::isConnectionAlive($connection)) {
                return $connection;
            }
        }
        
        return null;
    }

    /**
     * Create a new TCP connection
     */
    private static function createNewConnection(string $host, int $port): ?array
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
        if ($socket === false) {
            return null;
        }

        // Set socket options for optimal performance
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);
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
        
        // Connect to server
        if (socket_connect($socket, $host, $port)) {
            return [
                'socket' => $socket,
                'host' => $host,
                'port' => $port
            ];
        }
        
        socket_close($socket);
        return null;
    }

    /**
     * Check if connection is still alive
     */
    private static function isConnectionAlive(array $connection): bool
    {
        $socket = $connection['socket'];
        
        if (!is_resource($socket)) {
            return false;
        }
        
        // Check socket error state
        $error = socket_get_option($socket, SOL_SOCKET, SO_ERROR);
        if ($error !== 0) {
            return false;
        }
        
        // Check if connection is too old
        $now = time();
        if (($now - $connection['created_at']) > self::$connectionTimeout) {
            return false;
        }
        
        // Check if connection has been idle too long
        if (isset($connection['last_used']) && 
            ($now - $connection['last_used']) > self::$idleTimeout) {
            return false;
        }
        
        return true;
    }

    /**
     * Remove a connection from the pool
     */
    private static function removeConnection(array $connection): void
    {
        if (!isset($connection['pool_key']) || !isset(self::$pools[$connection['pool_key']])) {
            return;
        }

        $poolKey = $connection['pool_key'];
        $pool = &self::$pools[$poolKey];

        // Close socket if it's still a resource
        if (isset($connection['socket']) && is_resource($connection['socket'])) {
            socket_close($connection['socket']);
        }

        // Remove from pool
        $pool['connections'] = array_filter($pool['connections'], function($conn) use ($connection) {
            return $conn['id'] !== $connection['id'];
        });
        
        // Reindex array
        $pool['connections'] = array_values($pool['connections']);
    }

    /**
     * Clean up old and dead connections
     */
    public static function cleanup(): array
    {
        $stats = [
            'pools_cleaned' => 0,
            'connections_removed' => 0,
            'total_pools' => count(self::$pools)
        ];

        foreach (self::$pools as $poolKey => &$pool) {
            $initialCount = count($pool['connections']);
            
            $pool['connections'] = array_filter($pool['connections'], function($connection) {
                if (!self::isConnectionAlive($connection)) {
                    if (isset($connection['socket']) && is_resource($connection['socket'])) {
                        socket_close($connection['socket']);
                    }
                    return false;
                }
                return true;
            });
            
            // Reindex array
            $pool['connections'] = array_values($pool['connections']);
            $removed = $initialCount - count($pool['connections']);
            
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
    }

    /**
     * Generate pool key from host and port
     */
    private static function getPoolKey(string $host, int $port): string
    {
        return "{$host}:{$port}";
    }
}