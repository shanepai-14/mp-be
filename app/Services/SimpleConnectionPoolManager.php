<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SimpleConnectionPoolManager
{
    private static array $pools = [];
    private static bool $initialized = false;
    private static array $config = [];

    /**
     * Initialize the connection pool
     */
    public static function init(array $config = []): void
    {
        if (self::$initialized) {
            return;
        }

        self::$config = array_merge([
            'max_connections_per_pool' => 5,
            'connection_timeout' => 600,
            'idle_timeout' => 300,
            'connect_timeout' => 5,
            'socket_timeout' => 3
        ], $config);

        self::$initialized = true;

        Log::channel('gps_pool')->info('Simple connection pool initialized', [
            'process_id' => getmypid(),
            'config' => self::$config
        ]);
    }

    /**
     * Send data through pooled connection
     */
    public static function sendData(string $host, int $port, string $data, string $vehicleId): array
    {
        if (!self::$initialized) {
            self::init();
        }

        $poolKey = "{$host}:{$port}";
        $connection = self::getConnection($poolKey, $host, $port);
        
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
     * Get a connection from pool or create new one
     */
    private static function getConnection(string $poolKey, string $host, int $port): ?array
    {
        // Initialize pool if not exists
        if (!isset(self::$pools[$poolKey])) {
            self::$pools[$poolKey] = [
                'connections' => [],
                'host' => $host,
                'port' => $port,
                'created_at' => time(),
                'stats' => [
                    'total_created' => 0,
                    'total_reused' => 0
                ]
            ];
        }

        $pool = &self::$pools[$poolKey];
        
        // Try to get available connection
        foreach ($pool['connections'] as &$connection) {
            if (!$connection['in_use'] && self::isConnectionAlive($connection)) {
                $connection['in_use'] = true;
                $connection['last_used'] = time();
                $pool['stats']['total_reused']++;
                
                Log::channel('gps_pool')->debug("Reusing connection for {$poolKey}", [
                    'connection_id' => $connection['id'],
                    'process_id' => getmypid()
                ]);
                
                return $connection;
            }
        }
        
        // Create new connection if pool has space
        if (count($pool['connections']) < self::$config['max_connections_per_pool']) {
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
                'use_count' => 0
            ];
            
            $pool['connections'][] = $connection;
            $pool['stats']['total_created']++;
            
            Log::channel('gps_pool')->info("Created new connection for {$poolKey}", [
                'connection_id' => $connection['id'],
                'process_id' => getmypid(),
                'pool_size' => count($pool['connections'])
            ]);
            
            return $connection;
        }
        
        return null;
    }

    /**
     * Create socket connection
     */
    private static function createSocket(string $host, int $port)
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
        if ($socket === false) {
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
        
        socket_close($socket);
        return null;
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
     * Return connection to pool
     */
    private static function returnConnection(array $connection): void
    {
        $poolKey = $connection['pool_key'];
        
        if (!isset(self::$pools[$poolKey])) {
            return;
        }

        $pool = &self::$pools[$poolKey];
        
        foreach ($pool['connections'] as &$poolConnection) {
            if ($poolConnection['id'] === $connection['id']) {
                $poolConnection['in_use'] = false;
                $poolConnection['last_used'] = time();
                $poolConnection['use_count']++;
                break;
            }
        }
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
     * Remove connection from pool
     */
    private static function removeConnection(array $connection): void
    {
        $poolKey = $connection['pool_key'];
        
        if (!isset(self::$pools[$poolKey])) {
            return;
        }

        $pool = &self::$pools[$poolKey];
        
        if (isset($connection['socket']) && is_resource($connection['socket'])) {
            socket_close($connection['socket']);
        }

        $pool['connections'] = array_filter($pool['connections'], function($conn) use ($connection) {
            return $conn['id'] !== $connection['id'];
        });
        
        $pool['connections'] = array_values($pool['connections']);
    }

    /**
     * Get stats
     */
    public static function getStats(): array
    {
        $totalConnections = 0;
        $poolStats = [];
        
        foreach (self::$pools as $poolKey => $pool) {
            $activeCount = count(array_filter($pool['connections'], fn($conn) => $conn['in_use']));
            $totalConnections += count($pool['connections']);
            
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
            'process_id' => getmypid(),
            'total_pools' => count(self::$pools),
            'total_connections' => $totalConnections,
            'pools' => $poolStats
        ];
    }

    /**
     * Cleanup old connections
     */
    public static function cleanup(): array
    {
        $removed = 0;
        
        foreach (self::$pools as $poolKey => &$pool) {
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

        return [
            'process_id' => getmypid(),
            'connections_removed' => $removed
        ];
    }

    /**
     * Shutdown
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
}