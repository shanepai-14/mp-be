<?php

namespace App\Services;

use App\Models\ConnectionPoolStat;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class ConnectionPoolService
{
    private static array $config = [];
    private static string $processId = '';
    private static array $localConnections = []; // Process-local connections only
    private static ?Redis $redis = null;
    
    // Redis keys for shared pool management
    private const POOL_KEY_PREFIX = 'gps_pool:';
    private const POOL_STATS_KEY = 'gps_pool_stats:';
    private const POOL_LOCK_KEY = 'gps_pool_lock:';
    
    public static function init(array $config = []): void
    {
        self::$config = array_merge([
            'socket_timeout' => 5,
            'read_timeout_ms' => 100000,
            'max_retry_attempts' => 3,
            'retry_delay_ms' => 50,
            'connection_cache_seconds' => 30,
            // Shared pool settings
            'shared_pool_enabled' => true,
            'shared_pool_size' => 20,              // Total connections across all processes
            'local_pool_size' => 3,               // Max connections per process
            'pool_idle_timeout' => 300,
            'pool_max_lifetime' => 3600,
            'batch_size' => 20,
            'batch_delay_ms' => 50,
            'pool_cleanup_interval' => 60,
            'connection_lease_time' => 30,        // How long a process can hold a connection
        ], $config);

        self::$processId = (string) getmypid();
        self::$localConnections = [];
        
        // Initialize Redis connection for shared pool
        if (self::$config['shared_pool_enabled']) {
            try {
                self::$redis = Redis::connection('default');
            } catch (\Exception $e) {
                Log::warning("Redis not available, falling back to local pools", [
                    'error' => $e->getMessage()
                ]);
                self::$config['shared_pool_enabled'] = false;
            }
        }
        
        Log::channel('gps_pool')->info("SharedConnectionPoolService initialized", [
            'process_id' => self::$processId,
            'shared_pool_enabled' => self::$config['shared_pool_enabled'],
            'config' => self::$config
        ]);
    }

    /**
     * Send GPS data using shared connection pool
     */
    public static function sendGPSData(string $host, int $port, string $gpsData, string $vehicleId): array
    {
        if (empty(self::$config)) {
            self::init();
        }

        $startTime = microtime(true);
        $serverKey = "{$host}:{$port}";
        
        // Quick server availability check
        if (!self::isServerAvailableCached($serverKey, $host, $port)) {
            return [
                'success' => false,
                'error' => 'GPS server unavailable',
                'vehicle_id' => $vehicleId,
                'process_id' => self::$processId,
                'connection_reused' => false
            ];
        }

        // Try to get connection (shared or local)
        $connection = self::acquireConnection($serverKey, $host, $port);
        
        if (!$connection) {
            // Fallback to direct connection
            return self::sendDataDirectConnection($host, $port, $gpsData, $vehicleId);
        }

        try {
            $result = self::sendDataToSocket($connection['socket'], $gpsData, $vehicleId);
            
            // Update connection stats
            $connection['last_used'] = time();
            $connection['message_count']++;
            
            // Return or remove connection based on health
            if ($result['socket_alive_after']) {
                self::releaseConnection($serverKey, $connection);
            } else {
                self::removeConnection($serverKey, $connection['id']);
            }

            return array_merge($result, [
                'process_id' => self::$processId,
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'connection_reused' => true,
                'connection_id' => $connection['id'],
                'connection_source' => $connection['source'] ?? 'unknown'
            ]);

        } catch (\Exception $e) {
            self::removeConnection($serverKey, $connection['id']);
            
            // Fallback to direct connection
            return self::sendDataDirectConnection($host, $port, $gpsData, $vehicleId);
        }
    }

    /**
     * Send batch using connection pooling
     */
    public static function sendGPSDataBatch(string $host, int $port, array $messages): array
    {
        if (empty(self::$config)) {
            self::init();
        }

        $startTime = microtime(true);
        $serverKey = "{$host}:{$port}";
        $results = [];
        $totalMessages = count($messages);

        // Process in batches to maximize connection reuse
        $batches = array_chunk($messages, self::$config['batch_size']);
        $processedCount = 0;
        $successCount = 0;

        foreach ($batches as $batch) {
            $connection = self::acquireConnection($serverKey, $host, $port);
            
            if (!$connection) {
                // Process batch with direct connections
                foreach ($batch as $messageData) {
                    try {
                        $result = self::sendDataDirectConnection($host, $port, $messageData['gps_data'], $messageData['vehicle_id']);
                        $results[] = $result;
                        if ($result['success']) $successCount++;
                    } catch (\Exception $e) {
                        $results[] = [
                            'success' => false,
                            'error' => $e->getMessage(),
                            'vehicle_id' => $messageData['vehicle_id'],
                            'connection_reused' => false
                        ];
                    }
                    $processedCount++;
                }
                continue;
            }

            // Process batch with pooled connection
            try {
                foreach ($batch as $index => $messageData) {
                    if ($index > 0) {
                        usleep(self::$config['batch_delay_ms'] * 1000);
                    }

                    if (!self::isSocketAlive($connection['socket'])) {
                        self::removeConnection($serverKey, $connection['id']);
                        throw new \Exception('Connection died during batch');
                    }

                    $result = self::sendDataToSocket($connection['socket'], $messageData['gps_data'], $messageData['vehicle_id']);
                    $results[] = array_merge($result, [
                        'connection_reused' => true,
                        'connection_id' => $connection['id']
                    ]);
                    
                    $connection['last_used'] = time();
                    $connection['message_count']++;
                    $processedCount++;
                    
                    if ($result['success']) $successCount++;
                    
                    if (!$result['socket_alive_after']) {
                        self::removeConnection($serverKey, $connection['id']);
                        break;
                    }
                }
                
                // Return healthy connection
                if (isset($connection['socket']) && self::isSocketAlive($connection['socket'])) {
                    self::releaseConnection($serverKey, $connection);
                }
                
            } catch (\Exception $e) {
                self::removeConnection($serverKey, $connection['id']);
            }
        }

        return [
            'success' => $successCount > 0,
            'total_messages' => $totalMessages,
            'processed_messages' => $processedCount,
            'successful_messages' => $successCount,
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'connection_pooling_used' => true,
            'results' => $results
        ];
    }

    /**
     * Acquire connection from shared or local pool
     */
    private static function acquireConnection(string $serverKey, string $host, int $port): ?array
    {
        // Try local pool first (fastest)
        $connection = self::getLocalConnection($serverKey);
        if ($connection) {
            Log::channel('gps_pool')->debug("Using local pooled connection", [
                'server_key' => $serverKey,
                'connection_id' => $connection['id'],
                'process_id' => self::$processId
            ]);
            return $connection;
        }

        // Try shared pool if enabled
        if (self::$config['shared_pool_enabled'] && self::$redis) {
            $connection = self::getSharedConnection($serverKey, $host, $port);
            if ($connection) {
                Log::channel('gps_pool')->debug("Using shared pooled connection", [
                    'server_key' => $serverKey,
                    'connection_id' => $connection['id'],
                    'process_id' => self::$processId
                ]);
                return $connection;
            }
        }

        // Create new local connection if under limit
        if (count(self::$localConnections[$serverKey] ?? []) < self::$config['local_pool_size']) {
            return self::createNewConnection($serverKey, $host, $port);
        }

        return null;
    }

    /**
     * Get connection from local process pool
     */
    private static function getLocalConnection(string $serverKey): ?array
    {
        if (!isset(self::$localConnections[$serverKey])) {
            return null;
        }

        foreach (self::$localConnections[$serverKey] as $index => $connection) {
            if (!$connection['in_use'] && self::isSocketAlive($connection['socket'])) {
                self::$localConnections[$serverKey][$index]['in_use'] = true;
                self::$localConnections[$serverKey][$index]['last_used'] = time();
                return self::$localConnections[$serverKey][$index];
            }
        }

        return null;
    }

    /**
     * Get connection from shared Redis pool
     */
    private static function getSharedConnection(string $serverKey, string $host, int $port): ?array
    {
        if (!self::$redis) return null;

        $poolKey = self::POOL_KEY_PREFIX . $serverKey;
        $lockKey = self::POOL_LOCK_KEY . $serverKey;
        
        try {
            // Try to acquire lock
            if (!self::$redis->set($lockKey, self::$processId, 'EX', 2, 'NX')) {
                return null; // Another process is modifying pool
            }

            // Get available connections
            $connections = self::$redis->hgetall($poolKey);
            
            foreach ($connections as $connectionId => $connectionData) {
                $connection = json_decode($connectionData, true);
                
                if (!$connection['in_use'] && time() - $connection['last_used'] < self::$config['connection_lease_time']) {
                    // Lease this connection to current process
                    $connection['in_use'] = true;
                    $connection['leased_to'] = self::$processId;
                    $connection['lease_expires'] = time() + self::$config['connection_lease_time'];
                    
                    // Create new socket in this process (can't share socket handles)
                    $socket = self::createFastSocket($host, $port);
                    if ($socket) {
                        $connection['socket'] = $socket;
                        $connection['source'] = 'shared_pool';
                        
                        // Update in Redis
                        self::$redis->hset($poolKey, $connectionId, json_encode($connection));
                        self::$redis->del($lockKey);
                        
                        return $connection;
                    }
                }
            }
            
            self::$redis->del($lockKey);
            
        } catch (\Exception $e) {
            Log::warning("Shared pool access failed", ['error' => $e->getMessage()]);
            self::$redis->del($lockKey);
        }

        return null;
    }

    /**
     * Create new connection
     */
    private static function createNewConnection(string $serverKey, string $host, int $port): ?array
    {
        $socket = self::createFastSocket($host, $port);
        if (!$socket) return null;

        $connectionId = uniqid('pool_' . self::$processId . '_');
        $connection = [
            'id' => $connectionId,
            'socket' => $socket,
            'created_at' => time(),
            'last_used' => time(),
            'message_count' => 0,
            'in_use' => true,
            'host' => $host,
            'port' => $port,
            'process_id' => self::$processId,
            'source' => 'local_pool'
        ];

        // Add to local pool
        if (!isset(self::$localConnections[$serverKey])) {
            self::$localConnections[$serverKey] = [];
        }
        self::$localConnections[$serverKey][] = $connection;

        Log::channel('gps_pool')->info("Created new local connection", [
            'server_key' => $serverKey,
            'connection_id' => $connectionId,
            'process_id' => self::$processId,
            'local_pool_size' => count(self::$localConnections[$serverKey])
        ]);

        return $connection;
    }

    /**
     * Release connection back to pool
     */
    private static function releaseConnection(string $serverKey, array $connection): void
    {
        if ($connection['source'] === 'local_pool') {
            // Return to local pool
            foreach (self::$localConnections[$serverKey] ?? [] as &$localConn) {
                if ($localConn['id'] === $connection['id']) {
                    $localConn['in_use'] = false;
                    $localConn['last_used'] = $connection['last_used'];
                    $localConn['message_count'] = $connection['message_count'];
                    break;
                }
            }
        } elseif ($connection['source'] === 'shared_pool' && self::$redis) {
            // Return to shared pool
            $poolKey = self::POOL_KEY_PREFIX . $serverKey;
            $lockKey = self::POOL_LOCK_KEY . $serverKey;
            
            try {
                if (self::$redis->set($lockKey, self::$processId, 'EX', 2, 'NX')) {
                    $connection['in_use'] = false;
                    $connection['leased_to'] = null;
                    $connection['lease_expires'] = null;
                    unset($connection['socket']); // Can't store socket handle in Redis
                    
                    self::$redis->hset($poolKey, $connection['id'], json_encode($connection));
                    self::$redis->del($lockKey);
                }
            } catch (\Exception $e) {
                Log::warning("Failed to return connection to shared pool", ['error' => $e->getMessage()]);
                self::$redis->del($lockKey);
            }
        }
    }

    /**
     * Remove connection from pool
     */
    private static function removeConnection(string $serverKey, string $connectionId): void
    {
        // Remove from local pool
        if (isset(self::$localConnections[$serverKey])) {
            foreach (self::$localConnections[$serverKey] as $index => $connection) {
                if ($connection['id'] === $connectionId) {
                    if (isset($connection['socket'])) {
                        socket_close($connection['socket']);
                    }
                    unset(self::$localConnections[$serverKey][$index]);
                    self::$localConnections[$serverKey] = array_values(self::$localConnections[$serverKey]);
                    break;
                }
            }
        }

        // Remove from shared pool
        if (self::$redis) {
            $poolKey = self::POOL_KEY_PREFIX . $serverKey;
            $lockKey = self::POOL_LOCK_KEY . $serverKey;
            
            try {
                if (self::$redis->set($lockKey, self::$processId, 'EX', 2, 'NX')) {
                    self::$redis->hdel($poolKey, $connectionId);
                    self::$redis->del($lockKey);
                }
            } catch (\Exception $e) {
                self::$redis->del($lockKey);
            }
        }
    }

    // ... (keep all the other methods: createFastSocket, sendDataToSocket, 
    //      sendDataDirectConnection, readResponseFast, isSocketAlive, etc.)
    
    private static function createFastSocket(string $host, int $port)
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) return null;

        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, [
            "sec" => self::$config['socket_timeout'], "usec" => 0
        ]);
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, [
            "sec" => self::$config['socket_timeout'], "usec" => 0
        ]);

        if (!socket_connect($socket, $host, $port)) {
            socket_close($socket);
            return null;
        }
        
        return $socket;
    }
    
    private static function sendDataToSocket($socket, string $gpsData, string $vehicleId): array
    {
        $message = $gpsData . "\r";
        $bytesWritten = socket_write($socket, $message, strlen($message));
        
        if ($bytesWritten === false) {
            throw new \Exception('Failed to write data: ' . socket_strerror(socket_last_error($socket)));
        }
        
        $response = self::readResponseFast($socket);
        
        return [
            'success' => true,
            'response' => $response,
            'bytes_written' => $bytesWritten,
            'vehicle_id' => $vehicleId,
            'socket_alive_after' => self::isSocketAlive($socket)
        ];
    }
    
    private static function sendDataDirectConnection(string $host, int $port, string $gpsData, string $vehicleId): array
    {
        $socket = self::createFastSocket($host, $port);
        if (!$socket) {
            throw new \Exception('Failed to create socket connection');
        }

        try {
            return array_merge(
                self::sendDataToSocket($socket, $gpsData, $vehicleId),
                ['connection_reused' => false, 'connection_id' => 'single_use_' . uniqid()]
            );
        } finally {
            socket_close($socket);
        }
    }
    
    private static function readResponseFast($socket): string
    {
        $response = '';
        $read = [$socket];
        $write = $except = [];
        
        $selectResult = socket_select($read, $write, $except, 0, self::$config['read_timeout_ms']);
        
        if ($selectResult > 0 && in_array($socket, $read)) {
            $data = socket_read($socket, 1024);
            if ($data !== false && $data !== '') {
                $response = trim($data);
            }
        }
        
        return $response;
    }
    
    private static function isSocketAlive($socket): bool
    {
        if (!$socket) return false;
        
        $read = $except = [];
        $write = [$socket];
        
        $result = socket_select($read, $write, $except, 0, 1000);
        return $result !== false && ($result === 0 || in_array($socket, $write));
    }
    
    private static function isServerAvailableCached(string $serverKey, string $host, int $port): bool
    {
        $cacheKey = "gps_server_available_{$serverKey}";
        
        return Cache::remember($cacheKey, self::$config['connection_cache_seconds'], function() use ($host, $port) {
            $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if (!$socket) return false;
            
            socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ["sec" => 1, "usec" => 0]);
            $result = @socket_connect($socket, $host, $port);
            socket_close($socket);
            
            return $result !== false;
        });
    }

    public static function getStats(): array
    {
        $localStats = [];
        $totalLocal = 0;
        
        foreach (self::$localConnections as $serverKey => $connections) {
            $localStats[$serverKey] = count($connections);
            $totalLocal += count($connections);
        }

        return [
            'process_id' => self::$processId,
            'connection_strategy' => 'shared_connection_pooling',
            'shared_pool_enabled' => self::$config['shared_pool_enabled'],
            'local_connections' => $localStats,
            'total_local_connections' => $totalLocal,
            'config' => self::$config
        ];
    }

    public static function cleanup(): void
    {
        foreach (self::$localConnections as $serverKey => $connections) {
            foreach ($connections as $connection) {
                if (isset($connection['socket'])) {
                    socket_close($connection['socket']);
                }
            }
        }
        
        self::$localConnections = [];
        
        Log::channel('gps_pool')->info("Shared connection pool cleanup completed", [
            'process_id' => self::$processId
        ]);
    }
}