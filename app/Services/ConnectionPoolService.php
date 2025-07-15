<?php

namespace App\Services;

use App\Models\ConnectionPoolStat;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

class ConnectionPoolService
{
    private static array $config = [];
    private static string $processId = '';
    private static array $connectionPools = []; // Store connection pools by server
    private static array $poolStats = []; // Track pool statistics
    
    public static function init(array $config = []): void
    {
        self::$config = array_merge([
            'socket_timeout' => 5,
            'read_timeout_ms' => 100000,
            'max_retry_attempts' => 3,
            'retry_delay_ms' => 50,
            'connection_cache_seconds' => 30,
            // Connection pooling settings
            'pool_size' => 5,                    // Max connections per server
            'pool_idle_timeout' => 300,          // 5 minutes idle timeout
            'pool_max_lifetime' => 3600,         // 1 hour max connection lifetime
            'batch_size' => 20,                  // Optimal batch size for pooling
            'batch_delay_ms' => 50,              // Delay between messages in batch
            'pool_cleanup_interval' => 60,       // Cleanup every minute
        ], $config);

        self::$processId = (string) getmypid();
        
        // Initialize pools array
        self::$connectionPools = [];
        self::$poolStats = [];
        
        Log::channel('gps_pool')->info("ConnectionPoolService initialized with pooling enabled", [
            'process_id' => self::$processId,
            'config' => self::$config
        ]);
    }

    /**
     * Send single GPS message (will use pooling when possible)
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
                'attempts' => 0,
                'duration_ms' => 0,
                'connection_reused' => false
            ];
        }

        // Try to get connection from pool
        $connection = self::getPooledConnection($serverKey, $host, $port);
        
        if (!$connection) {
            // Fallback to single-use connection
            return self::sendDataDirectConnection($host, $port, $gpsData, $vehicleId);
        }

        $attempts = 0;
        $lastError = '';
        
        while ($attempts < self::$config['max_retry_attempts']) {
            $attempts++;
            
            try {
                // Check if pooled connection is still alive
                if (!self::isSocketAlive($connection['socket'])) {
                    self::removeFromPool($serverKey, $connection['id']);
                    throw new \Exception('Pooled connection died');
                }

                $result = self::sendDataToSocket(
                    $connection['socket'], 
                    $gpsData, 
                    $vehicleId
                );

                // Update connection stats
                $connection['last_used'] = time();
                $connection['message_count']++;
                
                // Return connection to pool if still alive
                if ($result['socket_alive_after']) {
                    self::returnToPool($serverKey, $connection);
                } else {
                    self::removeFromPool($serverKey, $connection['id']);
                }

                return array_merge($result, [
                    'process_id' => self::$processId,
                    'attempts' => $attempts,
                    'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    'connection_reused' => true,
                    'connection_id' => $connection['id'],
                    'pool_stats' => self::getPoolStats($serverKey)
                ]);

            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                self::removeFromPool($serverKey, $connection['id']);
                
                if ($attempts < self::$config['max_retry_attempts']) {
                    // Try to get another connection from pool
                    $connection = self::getPooledConnection($serverKey, $host, $port);
                    if (!$connection) {
                        // No more pooled connections, fallback to direct
                        return self::sendDataDirectConnection($host, $port, $gpsData, $vehicleId);
                    }
                    usleep(self::$config['retry_delay_ms'] * 1000);
                    continue;
                }
            }
        }

        return [
            'success' => false,
            'error' => $lastError,
            'vehicle_id' => $vehicleId,
            'process_id' => self::$processId,
            'attempts' => $attempts,
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'connection_reused' => false
        ];
    }

    /**
     * Send batch of GPS messages using connection pooling (RECOMMENDED)
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

        // Quick server availability check
        if (!self::isServerAvailableCached($serverKey, $host, $port)) {
            return [
                'success' => false,
                'error' => 'GPS server unavailable',
                'total_messages' => $totalMessages,
                'processed_messages' => 0,
                'successful_messages' => 0,
                'results' => []
            ];
        }

        // Process messages in optimal batches
        $batches = array_chunk($messages, self::$config['batch_size']);
        $processedCount = 0;
        $successCount = 0;

        foreach ($batches as $batchIndex => $batch) {
            $batchResult = self::processBatch($serverKey, $host, $port, $batch);
            
            foreach ($batchResult['results'] as $index => $result) {
                $results[$processedCount] = $result;
                $processedCount++;
                if ($result['success']) {
                    $successCount++;
                }
            }

            // Small delay between batches to avoid overwhelming server
            if ($batchIndex < count($batches) - 1) {
                usleep(10000); // 10ms between batches
            }
        }

        return [
            'success' => $successCount > 0,
            'total_messages' => $totalMessages,
            'processed_messages' => $processedCount,
            'successful_messages' => $successCount,
            'failed_messages' => $totalMessages - $successCount,
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'avg_time_per_message_ms' => $processedCount > 0 ? 
                round(((microtime(true) - $startTime) * 1000) / $processedCount, 2) : 0,
            'batches_processed' => count($batches),
            'connection_pooling_used' => true,
            'pool_stats' => self::getPoolStats($serverKey),
            'results' => $results
        ];
    }

    /**
     * Process a batch of messages using pooled connection
     */
    private static function processBatch(string $serverKey, string $host, int $port, array $batch): array
    {
        $connection = self::getPooledConnection($serverKey, $host, $port);
        $results = [];
        
        if (!$connection) {
            // Fallback to multiple single connections
            foreach ($batch as $index => $messageData) {
                try {
                    $result = self::sendDataDirectConnection($host, $port, $messageData['gps_data'], $messageData['vehicle_id']);
                    $results[$index] = $result;
                } catch (\Exception $e) {
                    $results[$index] = [
                        'success' => false,
                        'error' => $e->getMessage(),
                        'vehicle_id' => $messageData['vehicle_id'],
                        'connection_reused' => false
                    ];
                }
            }
            return ['results' => $results];
        }

        try {
            foreach ($batch as $index => $messageData) {
                // Add delay between messages
                if ($index > 0) {
                    usleep(self::$config['batch_delay_ms'] * 1000);
                }

                // Check connection health
                if (!self::isSocketAlive($connection['socket'])) {
                    self::removeFromPool($serverKey, $connection['id']);
                    throw new \Exception('Connection died during batch processing');
                }

                try {
                    $result = self::sendDataToSocket(
                        $connection['socket'], 
                        $messageData['gps_data'], 
                        $messageData['vehicle_id']
                    );
                    
                    $results[$index] = array_merge($result, [
                        'connection_reused' => true,
                        'connection_id' => $connection['id']
                    ]);
                    
                    $connection['last_used'] = time();
                    $connection['message_count']++;
                    
                    // If socket died, stop processing this batch
                    if (!$result['socket_alive_after']) {
                        self::removeFromPool($serverKey, $connection['id']);
                        break;
                    }
                    
                } catch (\Exception $e) {
                    $results[$index] = [
                        'success' => false,
                        'error' => $e->getMessage(),
                        'vehicle_id' => $messageData['vehicle_id'],
                        'connection_reused' => false
                    ];
                    break;
                }
            }
            
            // Return healthy connection to pool
            if (isset($connection['socket']) && self::isSocketAlive($connection['socket'])) {
                self::returnToPool($serverKey, $connection);
            }
            
        } catch (\Exception $e) {
            // Connection failed, remove from pool
            self::removeFromPool($serverKey, $connection['id']);
            
            // Process remaining messages with direct connections
            $remainingMessages = array_slice($batch, count($results));
            foreach ($remainingMessages as $index => $messageData) {
                try {
                    $result = self::sendDataDirectConnection($host, $port, $messageData['gps_data'], $messageData['vehicle_id']);
                    $results[count($results)] = $result;
                } catch (\Exception $e) {
                    $results[count($results)] = [
                        'success' => false,
                        'error' => $e->getMessage(),
                        'vehicle_id' => $messageData['vehicle_id'],
                        'connection_reused' => false
                    ];
                }
            }
        }

        return ['results' => $results];
    }

    /**
     * Get connection from pool or create new one
     */
    private static function getPooledConnection(string $serverKey, string $host, int $port): ?array
    {
        self::cleanupExpiredConnections($serverKey);
        
        if (!isset(self::$connectionPools[$serverKey])) {
            self::$connectionPools[$serverKey] = [];
        }

        $pool = &self::$connectionPools[$serverKey];
        
        // Try to get existing connection
        foreach ($pool as $index => $connection) {
            if (!$connection['in_use'] && self::isSocketAlive($connection['socket'])) {
                $pool[$index]['in_use'] = true;
                $pool[$index]['last_used'] = time();
                
                Log::channel('gps_pool')->debug("Reusing pooled connection", [
                    'server_key' => $serverKey,
                    'connection_id' => $connection['id'],
                    'message_count' => $connection['message_count']
                ]);
                
                return $pool[$index];
            }
        }

        // Create new connection if pool not full
        if (count($pool) < self::$config['pool_size']) {
            $socket = self::createFastSocket($host, $port);
            if ($socket) {
                $connectionId = uniqid('pool_');
                $newConnection = [
                    'id' => $connectionId,
                    'socket' => $socket,
                    'created_at' => time(),
                    'last_used' => time(),
                    'message_count' => 0,
                    'in_use' => true,
                    'host' => $host,
                    'port' => $port
                ];
                
                $pool[] = $newConnection;
                
                Log::channel('gps_pool')->info("Created new pooled connection", [
                    'server_key' => $serverKey,
                    'connection_id' => $connectionId,
                    'pool_size' => count($pool)
                ]);
                
                return $newConnection;
            }
        }

        return null;
    }

    /**
     * Return connection to pool
     */
    private static function returnToPool(string $serverKey, array $connection): void
    {
        if (!isset(self::$connectionPools[$serverKey])) {
            return;
        }

        foreach (self::$connectionPools[$serverKey] as &$poolConnection) {
            if ($poolConnection['id'] === $connection['id']) {
                $poolConnection['in_use'] = false;
                $poolConnection['last_used'] = $connection['last_used'];
                $poolConnection['message_count'] = $connection['message_count'];
                break;
            }
        }
    }

    /**
     * Remove connection from pool
     */
    private static function removeFromPool(string $serverKey, string $connectionId): void
    {
        if (!isset(self::$connectionPools[$serverKey])) {
            return;
        }

        foreach (self::$connectionPools[$serverKey] as $index => $connection) {
            if ($connection['id'] === $connectionId) {
                if (isset($connection['socket'])) {
                    socket_close($connection['socket']);
                }
                unset(self::$connectionPools[$serverKey][$index]);
                
                Log::channel('gps_pool')->debug("Removed connection from pool", [
                    'server_key' => $serverKey,
                    'connection_id' => $connectionId
                ]);
                break;
            }
        }
        
        // Reindex array
        self::$connectionPools[$serverKey] = array_values(self::$connectionPools[$serverKey]);
    }

    /**
     * Clean up expired connections
     */
    private static function cleanupExpiredConnections(string $serverKey): void
    {
        if (!isset(self::$connectionPools[$serverKey])) {
            return;
        }

        $now = time();
        $pool = &self::$connectionPools[$serverKey];
        
        foreach ($pool as $index => $connection) {
            $shouldRemove = false;
            
            // Check if connection is too old
            if ($now - $connection['created_at'] > self::$config['pool_max_lifetime']) {
                $shouldRemove = true;
            }
            
            // Check if connection has been idle too long
            if ($now - $connection['last_used'] > self::$config['pool_idle_timeout']) {
                $shouldRemove = true;
            }
            
            // Check if connection is dead
            if (!self::isSocketAlive($connection['socket'])) {
                $shouldRemove = true;
            }
            
            if ($shouldRemove) {
                if (isset($connection['socket'])) {
                    socket_close($connection['socket']);
                }
                unset($pool[$index]);
                
                Log::channel('gps_pool')->debug("Cleaned up expired connection", [
                    'server_key' => $serverKey,
                    'connection_id' => $connection['id']
                ]);
            }
        }
        
        // Reindex array
        self::$connectionPools[$serverKey] = array_values(self::$connectionPools[$serverKey]);
    }

    /**
     * Get pool statistics
     */
    private static function getPoolStats(string $serverKey): array
    {
        if (!isset(self::$connectionPools[$serverKey])) {
            return [
                'total_connections' => 0,
                'active_connections' => 0,
                'idle_connections' => 0
            ];
        }

        $pool = self::$connectionPools[$serverKey];
        $activeCount = 0;
        $totalMessages = 0;

        foreach ($pool as $connection) {
            if ($connection['in_use']) {
                $activeCount++;
            }
            $totalMessages += $connection['message_count'];
        }

        return [
            'total_connections' => count($pool),
            'active_connections' => $activeCount,
            'idle_connections' => count($pool) - $activeCount,
            'total_messages_processed' => $totalMessages,
            'pool_efficiency' => count($pool) > 0 ? round($totalMessages / count($pool), 2) : 0
        ];
    }

    /**
     * Fallback to single-use connection
     */
    private static function sendDataDirectConnection(string $host, int $port, string $gpsData, string $vehicleId): array
    {
        $socket = self::createFastSocket($host, $port);
        
        if (!$socket) {
            throw new \Exception('Failed to create socket connection');
        }

        try {
            $message = $gpsData . "\r";
            $bytesWritten = socket_write($socket, $message, strlen($message));
            
            if ($bytesWritten === false) {
                throw new \Exception('Failed to write data: ' . socket_strerror(socket_last_error($socket)));
            }
            
            if ($bytesWritten === 0) {
                throw new \Exception('No bytes written to socket');
            }

            $response = self::readResponseFast($socket);
            
            return [
                'success' => true,
                'response' => $response,
                'bytes_written' => $bytesWritten,
                'vehicle_id' => $vehicleId,
                'connection_id' => 'single_use_' . uniqid(),
                'connection_reused' => false
            ];
            
        } finally {
            socket_close($socket);
        }
    }

    /**
     * Send data to existing socket
     */
    private static function sendDataToSocket($socket, string $gpsData, string $vehicleId): array
    {
        if (!$socket) {
            throw new \Exception('Invalid socket provided');
        }

        $message = $gpsData . "\r";
        $bytesWritten = socket_write($socket, $message, strlen($message));
        
        if ($bytesWritten === false) {
            throw new \Exception('Failed to write data: ' . socket_strerror(socket_last_error($socket)));
        }
        
        if ($bytesWritten === 0) {
            throw new \Exception('No bytes written to socket');
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

    /**
     * Create optimized socket
     */
    private static function createFastSocket(string $host, int $port)
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
        if ($socket === false) {
            return null;
        }

        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, [
            "sec" => self::$config['socket_timeout'], 
            "usec" => 0
        ]);
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, [
            "sec" => self::$config['socket_timeout'], 
            "usec" => 0
        ]);

        if (!socket_connect($socket, $host, $port)) {
            socket_close($socket);
            return null;
        }
        
        return $socket;
    }

    /**
     * Fast response reading
     */
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

    /**
     * Check if socket is alive
     */
    private static function isSocketAlive($socket): bool
    {
        if (!$socket) return false;
        
        $read = $except = [];
        $write = [$socket];
        
        $result = socket_select($read, $write, $except, 0, 1000);
        
        if ($result === false) return false;
        if ($result === 0) return true;
        
        return in_array($socket, $write);
    }

    /**
     * Cached server availability check
     */
    private static function isServerAvailableCached(string $serverKey, string $host, int $port): bool
    {
        $cacheKey = "gps_server_available_{$serverKey}";
        
        return Cache::remember($cacheKey, self::$config['connection_cache_seconds'], function() use ($host, $port) {
            $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if (!$socket) return false;
            
            socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ["sec" => 1, "usec" => 0]);
            socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ["sec" => 1, "usec" => 0]);
            
            $result = @socket_connect($socket, $host, $port);
            socket_close($socket);
            
            return $result !== false;
        });
    }

    /**
     * Get comprehensive statistics
     */
    public static function getStats(): array
    {
        $totalConnections = 0;
        $totalMessages = 0;
        $serverStats = [];

        foreach (self::$connectionPools as $serverKey => $pool) {
            $stats = self::getPoolStats($serverKey);
            $totalConnections += $stats['total_connections'];
            $totalMessages += $stats['total_messages_processed'];
            $serverStats[$serverKey] = $stats;
        }

        return [
            'process_id' => self::$processId,
            'connection_strategy' => 'connection_pooling_enabled',
            'server_behavior' => 'supports_persistent_connections',
            'total_pools' => count(self::$connectionPools),
            'total_connections' => $totalConnections,
            'total_messages_processed' => $totalMessages,
            'server_stats' => $serverStats,
            'config' => self::$config
        ];
    }

    /**
     * Health check
     */
    public static function healthCheck(string $host, int $port): array
    {
        $startTime = microtime(true);
        $serverKey = "{$host}:{$port}";
        
        $available = self::isServerAvailableCached($serverKey, $host, $port);
        $checkTime = microtime(true) - $startTime;
        
        return [
            'server_available' => $available,
            'check_time_ms' => round($checkTime * 1000, 2),
            'process_id' => self::$processId,
            'server_type' => 'pooled_connections',
            'connection_reuse_possible' => true,
            'pool_stats' => self::getPoolStats($serverKey)
        ];
    }

    /**
     * Cleanup all connections
     */
    public static function cleanup(): void
    {
        foreach (self::$connectionPools as $serverKey => $pool) {
            foreach ($pool as $connection) {
                if (isset($connection['socket'])) {
                    socket_close($connection['socket']);
                }
            }
        }
        
        self::$connectionPools = [];
        
        Log::channel('gps_pool')->info("Connection pool cleanup completed", [
            'process_id' => self::$processId
        ]);
    }

    /**
     * Force cleanup of specific server pool
     */
    public static function cleanupServer(string $host, int $port): void
    {
        $serverKey = "{$host}:{$port}";
        
        if (isset(self::$connectionPools[$serverKey])) {
            foreach (self::$connectionPools[$serverKey] as $connection) {
                if (isset($connection['socket'])) {
                    socket_close($connection['socket']);
                }
            }
            unset(self::$connectionPools[$serverKey]);
        }
    }
}