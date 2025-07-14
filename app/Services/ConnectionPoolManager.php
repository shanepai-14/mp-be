<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ConnectionPoolManager
{
    private static array $localPools = [];
    private static bool $initialized = false;
    private static array $config = [];
    
    // Redis keys for persistent storage
    private const POOL_STATS_KEY = 'gps_pool_stats';
    private const POOL_CONFIG_KEY = 'gps_pool_config';
    
    /**
     * Initialize the connection pool with configuration
     */
    public static function init(array $config = []): void
    {
        if (self::$initialized) {
            return;
        }

        self::$config = array_merge([
            'max_connections_per_pool' => 20,
            'connection_timeout' => 300,
            'idle_timeout' => 120,
            'connect_timeout' => 5,
            'socket_timeout' => 3
        ], $config);

        // Store config in Redis for other processes
        Cache::put(self::POOL_CONFIG_KEY, self::$config, 3600);
        
        self::$initialized = true;

        Log::channel('gps_pool')->info('Persistent connection pool initialized', [
            'process_id' => getmypid(),
            'config' => self::$config
        ]);
    }

    /**
     * Get a connection (with local caching to avoid Redis overhead)
     */
    public static function getConnection(string $host, int $port): ?array
    {
        if (!self::$initialized) {
            self::init();
        }

        $poolKey = self::getPoolKey($host, $port);
        
        // First, try to get from local cache (within same request)
        if (isset(self::$localPools[$poolKey])) {
            $connection = self::getAvailableLocalConnection($poolKey);
            if ($connection) {
                return $connection;
            }
        }

        // Create new connection if needed
        return self::createNewConnection($host, $port, $poolKey);
    }

    /**
     * Get available connection from local pool
     */
    private static function getAvailableLocalConnection(string $poolKey): ?array
    {
        $pool = &self::$localPools[$poolKey];
        
        foreach ($pool['connections'] as &$connection) {
            if (!$connection['in_use'] && self::isConnectionAlive($connection)) {
                $connection['in_use'] = true;
                $connection['last_used'] = time();
                
                // Update stats in Redis
                self::incrementStat($poolKey, 'total_reused');
                
                Log::channel('gps_pool')->debug("Reusing local connection for {$poolKey}", [
                    'connection_id' => $connection['id'],
                    'process_id' => getmypid()
                ]);
                
                return $connection;
            }
        }
        
        return null;
    }

    /**
     * Create new connection and add to local pool
     */
    private static function createNewConnection(string $host, int $port, string $poolKey): ?array
    {
        // Initialize local pool if not exists
        if (!isset(self::$localPools[$poolKey])) {
            self::$localPools[$poolKey] = [
                'connections' => [],
                'host' => $host,
                'port' => $port,
                'created_at' => time(),
            ];
        }

        $pool = &self::$localPools[$poolKey];
        
        // Check if we can create more connections (global limit via Redis)
        $globalStats = self::getGlobalStats($poolKey);
        if ($globalStats['total_connections'] >= self::$config['max_connections_per_pool']) {
            Log::channel('gps_pool')->warning("Global connection limit reached for {$poolKey}");
            return null;
        }
        
        $socket = self::createSocket($host, $port);
        if (!$socket) {
            return null;
        }

        $connection = [
            'id' => uniqid(),
            'socket' => $socket,
            'host' => $host,
            'port' => $port,
            'pool_key' => $poolKey,
            'created_at' => time(),
            'last_used' => time(),
            'in_use' => true,
            'use_count' => 0,
            'process_id' => getmypid()
        ];
        
        $pool['connections'][] = $connection;
        
        // Update global stats
        self::incrementStat($poolKey, 'total_created');
        self::incrementStat($poolKey, 'active_connections');
        
        Log::channel('gps_pool')->info("Created new connection for {$poolKey}", [
            'connection_id' => $connection['id'],
            'process_id' => getmypid(),
            'local_pool_size' => count($pool['connections'])
        ]);
        
        return $connection;
    }

    /**
     * Create socket connection
     */
    private static function createSocket(string $host, int $port)
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
        if ($socket === false) {
            Log::channel('gps_pool')->error("Failed to create socket: " . socket_strerror(socket_last_error()));
            return null;
        }

        // Set socket options
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
        socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
        
        if (@socket_connect($socket, $host, $port)) {
            return $socket;
        }
        
        $error = socket_last_error($socket);
        socket_close($socket);
        
        Log::channel('gps_pool')->warning("Failed to connect to {$host}:{$port}: " . 
            socket_strerror($error));
        
        return null;
    }

    /**
     * Return connection to pool
     */
    public static function returnConnection(array $connection): void
    {
        $poolKey = $connection['pool_key'];
        
        if (!isset(self::$localPools[$poolKey])) {
            return;
        }

        $pool = &self::$localPools[$poolKey];
        
        foreach ($pool['connections'] as &$poolConnection) {
            if ($poolConnection['id'] === $connection['id']) {
                $poolConnection['in_use'] = false;
                $poolConnection['last_used'] = time();
                $poolConnection['use_count']++;
                
                Log::channel('gps_pool')->debug("Returned connection {$connection['id']} to local pool");
                break;
            }
        }
    }

    /**
     * Send data through connection with retry
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
            self::removeConnection($connection);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'vehicle_id' => $vehicleId
            ];
        }
    }

    /**
     * Write and read from socket
     */
    private static function writeAndRead(array $connection, string $data, string $vehicleId): array
    {
        $socket = $connection['socket'];
        $message = $data . "\r";
        
        $bytesWritten = socket_write($socket, $message, strlen($message));
        
        if ($bytesWritten === false) {
            throw new \Exception("Failed to write data: " . socket_strerror(socket_last_error($socket)));
        }
        
        if ($bytesWritten === 0) {
            throw new \Exception("No bytes written to socket");
        }

        // Try to read response (non-blocking)
        socket_set_nonblock($socket);
        $response = '';
        $startTime = microtime(true);
        
        while ((microtime(true) - $startTime) < 1.0) {
            $data = socket_read($socket, 2048);
            
            if ($data !== false && $data !== '') {
                $response = $data;
                break;
            }
            
            if ($data === false) {
                $error = socket_last_error($socket);
                if ($error !== SOCKET_EAGAIN && $error !== SOCKET_EWOULDBLOCK) {
                    socket_set_block($socket);
                    throw new \Exception("Read failed: " . socket_strerror($error));
                }
            }
            
            usleep(10000); // 10ms
        }
        
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
     * Check if connection is alive
     */
    private static function isConnectionAlive(array $connection): bool
    {
        $socket = $connection['socket'];
        
        if (!is_resource($socket)) {
            return false;
        }
        
        $error = socket_get_option($socket, SOL_SOCKET, SO_ERROR);
        if ($error !== 0) {
            return false;
        }
        
        $now = time();
        if (($now - $connection['created_at']) > self::$config['connection_timeout']) {
            return false;
        }
        
        if (($now - $connection['last_used']) > self::$config['idle_timeout']) {
            return false;
        }
        
        return true;
    }

    /**
     * Remove connection from local pool
     */
    private static function removeConnection(array $connection): void
    {
        $poolKey = $connection['pool_key'];
        
        if (!isset(self::$localPools[$poolKey])) {
            return;
        }

        $pool = &self::$localPools[$poolKey];
        
        if (isset($connection['socket']) && is_resource($connection['socket'])) {
            socket_close($connection['socket']);
        }

        $pool['connections'] = array_filter($pool['connections'], function($conn) use ($connection) {
            return $conn['id'] !== $connection['id'];
        });
        
        $pool['connections'] = array_values($pool['connections']);
        
        // Update global stats
        self::decrementStat($poolKey, 'active_connections');
    }

    /**
     * Get global stats from Redis
     */
    private static function getGlobalStats(string $poolKey): array
    {
        $stats = Cache::get(self::POOL_STATS_KEY . ":{$poolKey}", [
            'total_connections' => 0,
            'total_created' => 0,
            'total_reused' => 0,
            'active_connections' => 0
        ]);
        
        return $stats;
    }

    /**
     * Increment stat in Redis
     */
    private static function incrementStat(string $poolKey, string $stat): void
    {
        $key = self::POOL_STATS_KEY . ":{$poolKey}";
        $stats = Cache::get($key, []);
        $stats[$stat] = ($stats[$stat] ?? 0) + 1;
        Cache::put($key, $stats, 3600);
    }

    /**
     * Decrement stat in Redis
     */
    private static function decrementStat(string $poolKey, string $stat): void
    {
        $key = self::POOL_STATS_KEY . ":{$poolKey}";
        $stats = Cache::get($key, []);
        $stats[$stat] = max(0, ($stats[$stat] ?? 0) - 1);
        Cache::put($key, $stats, 3600);
    }

    /**
     * Get comprehensive stats
     */
    public static function getStats(): array
    {
        $localStats = [];
        $totalLocal = 0;
        
        foreach (self::$localPools as $poolKey => $pool) {
            $activeCount = count(array_filter($pool['connections'], fn($conn) => $conn['in_use']));
            $totalLocal += count($pool['connections']);
            
            $globalStats = self::getGlobalStats($poolKey);
            
            $localStats[$poolKey] = [
                'local_connections' => count($pool['connections']),
                'local_active' => $activeCount,
                'local_idle' => count($pool['connections']) - $activeCount,
                'global_created' => $globalStats['total_created'] ?? 0,
                'global_reused' => $globalStats['total_reused'] ?? 0,
                'global_active' => $globalStats['active_connections'] ?? 0,
                'process_id' => getmypid()
            ];
        }

        return [
            'process_id' => getmypid(),
            'local_pools' => count(self::$localPools),
            'total_local_connections' => $totalLocal,
            'pools' => $localStats
        ];
    }

    /**
     * Cleanup local connections only (don't shutdown entire pool)
     */
    public static function cleanup(): array
    {
        $removed = 0;
        
        foreach (self::$localPools as $poolKey => &$pool) {
            $initialCount = count($pool['connections']);
            
            $pool['connections'] = array_filter($pool['connections'], function($connection) {
                if (!self::isConnectionAlive($connection)) {
                    if (is_resource($connection['socket'])) {
                        socket_close($connection['socket']);
                    }
                    return false;
                }
                return true;
            });
            
            $pool['connections'] = array_values($pool['connections']);
            $removed += $initialCount - count($pool['connections']);
        }

        if ($removed > 0) {
            Log::channel('gps_pool')->info("Local cleanup completed", [
                'process_id' => getmypid(),
                'connections_removed' => $removed
            ]);
        }

        return [
            'process_id' => getmypid(),
            'connections_removed' => $removed
        ];
    }

    /**
     * Shutdown local pools only (for graceful process termination)
     */
    public static function shutdown(): void
    {
        foreach (self::$localPools as $poolKey => $pool) {
            foreach ($pool['connections'] as $connection) {
                if (isset($connection['socket']) && is_resource($connection['socket'])) {
                    socket_close($connection['socket']);
                }
                // Decrement global active count
                self::decrementStat($poolKey, 'active_connections');
            }
        }
        
        self::$localPools = [];
        
        Log::channel('gps_pool')->info("Local connection pool shutdown", [
            'process_id' => getmypid()
        ]);
    }

    /**
     * Generate pool key
     */
    private static function getPoolKey(string $host, int $port): string
    {
        return "{$host}:{$port}";
    }
}