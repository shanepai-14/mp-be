<?php

namespace App\Services;

use App\Models\ConnectionPoolStat;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ConnectionPoolService
{
    private static array $config = [];
    private static string $processId = '';
    private static array $localConnections = [];
    
    public static function init(array $config = []): void
    {
        self::$config = array_merge([
            'max_connections_per_pool' => 15,       // Increased significantly
            'connection_timeout' => 300,
            'idle_timeout' => 120,                  // Increased for reuse
            'socket_timeout' => 3,
            'lock_timeout' => 1,                    // Very short locks
            'max_retry_attempts' => 4,              // More attempts
            'retry_delay_ms' => 25,                 // Faster retries
            'local_connection_limit' => 3,          // More local connections
            'force_create_after_attempts' => 2,     // Force create sooner
            'aggressive_mode' => true,              // Always try to create connections
        ], $config);

        self::$processId = (string) getmypid();
    }

    public static function sendGPSData(string $host, int $port, string $gpsData, string $vehicleId): array
    {
        if (empty(self::$config)) {
            self::init();
        }

        $poolKey = "{$host}:{$port}";
        $attempts = 0;
        $lastError = '';
        $startTime = microtime(true);
        
        while ($attempts < self::$config['max_retry_attempts']) {
            $attempts++;
            $connection = null;
            
            try {
                // Aggressive connection strategy - try multiple approaches quickly
                $connection = self::getConnectionAggressive($poolKey, $host, $port, $attempts);
                
                if (!$connection) {
                    $lastError = 'Could not create connection after all attempts';
                    
                    if ($attempts < self::$config['max_retry_attempts']) {
                        // Very short delay for high volume
                        usleep(self::$config['retry_delay_ms'] * 1000);
                        continue;
                    }
                    break;
                }

                $result = self::sendAndReceive($connection, $gpsData, $vehicleId);
                
                // Success - clean up and return
                self::releaseConnectionQuick($poolKey, $connection);
                self::storeLocalConnectionQuick($poolKey, $connection);
                self::updateStatsQuick($poolKey, 'success', $connection['reused']);
                
                return array_merge($result, [
                    'reused' => $connection['reused'],
                    'connection_id' => $connection['id'],
                    'process_id' => self::$processId,
                    'attempts' => $attempts,
                    'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    'force_created' => $connection['force_created'] ?? false,
                    'direct_connection' => $connection['direct'] ?? false
                ]);
                
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                
                if ($connection) {
                    self::cleanupFailedConnectionQuick($poolKey, $connection);
                }
                
                // Quick retry for high volume
                if ($attempts < self::$config['max_retry_attempts']) {
                    usleep(self::$config['retry_delay_ms'] * 1000);
                    continue;
                }
            }
        }
        
        // All attempts failed
        self::updateStatsQuick($poolKey, 'connection_failed');
        
        return [
            'success' => false,
            'error' => $lastError,
            'vehicle_id' => $vehicleId,
            'process_id' => self::$processId,
            'attempts' => $attempts,
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
        ];
    }

    /**
     * Aggressive connection strategy - try everything quickly
     */
    private static function getConnectionAggressive(string $poolKey, string $host, int $port, int $attempt): ?array
    {
        // Attempt 1: Local connection (fastest)
        if ($attempt === 1) {
            $connection = self::getLocalConnectionQuick($poolKey);
            if ($connection) return $connection;
        }
        
        // Attempt 2: Try force create (bypass shared pool complexity)
        if ($attempt >= self::$config['force_create_after_attempts']) {
            $connection = self::forceCreateConnectionOptimized($host, $port, $poolKey);
            if ($connection) return $connection;
        }
        
        // Attempt 3-4: Try shared pool with minimal locking
        if ($attempt <= 3) {
            $connection = self::getSharedConnectionMinimal($poolKey, $host, $port);
            if ($connection) return $connection;
        }
        
        // Final attempt: Always force create
        return self::forceCreateConnectionOptimized($host, $port, $poolKey);
    }

    /**
     * Quick local connection retrieval
     */
    private static function getLocalConnectionQuick(string $poolKey): ?array
    {
        if (!isset(self::$localConnections[$poolKey])) {
            return null;
        }

        foreach (self::$localConnections[$poolKey] as $connId => $connection) {
            if (self::isConnectionValidQuick($connection)) {
                $connection['reused'] = true;
                $connection['use_count']++;
                $connection['last_used'] = time();
                
                self::$localConnections[$poolKey][$connId] = $connection;
                return $connection;
            } else {
                // Remove invalid connection
                if (is_resource($connection['socket'])) {
                    socket_close($connection['socket']);
                }
                unset(self::$localConnections[$poolKey][$connId]);
            }
        }

        return null;
    }

    /**
     * Optimized force connection creation for high volume
     */
    private static function forceCreateConnectionOptimized(string $host, int $port, string $poolKey): ?array
    {
        $socket = self::createSocketFast($host, $port);
        if (!$socket) {
            return null;
        }

        $connId = 'force_' . uniqid('', true);
        
        return [
            'id' => $connId,
            'socket' => $socket,
            'host' => $host,
            'port' => $port,
            'created_at' => time(),
            'last_used' => time(),
            'use_count' => 1,
            'reused' => false,
            'force_created' => true
        ];
    }

    /**
     * Minimal shared connection with very short locks
     */
    private static function getSharedConnectionMinimal(string $poolKey, string $host, int $port): ?array
    {
        try {
            $connections = self::getCachedConnectionsQuick($poolKey);
            
            // Quick scan for available connections
            foreach ($connections as $connId => $connData) {
                if (!$connData['in_use'] && 
                    (time() - $connData['last_used']) < self::$config['idle_timeout']) {
                    
                    // Try very quick claim
                    if (self::quickClaimConnection($poolKey, $connId)) {
                        $socket = self::createSocketFast($connData['host'], $connData['port']);
                        
                        if ($socket) {
                            return [
                                'id' => $connId,
                                'socket' => $socket,
                                'host' => $connData['host'],
                                'port' => $connData['port'],
                                'created_at' => $connData['created_at'],
                                'last_used' => time(),
                                'use_count' => $connData['use_count'] + 1,
                                'reused' => true,
                                'claimed_from_shared' => true
                            ];
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignore shared pool errors, fall back to force create
        }

        return null;
    }

    /**
     * Fast socket creation optimized for high volume
     */
    private static function createSocketFast(string $host, int $port)
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
        if ($socket === false) {
            return null;
        }

        // Minimal socket options for speed
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
        
        // Short timeouts for high volume
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ["sec" => 2, "usec" => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ["sec" => 2, "usec" => 0]);

        // Non-blocking connect for speed
        socket_set_nonblock($socket);
        $result = socket_connect($socket, $host, $port);
        
        if ($result === false) {
            $error = socket_last_error($socket);
            
            if ($error === SOCKET_EINPROGRESS || $error === SOCKET_EALREADY || $error === SOCKET_EWOULDBLOCK) {
                // Wait for connection with short timeout
                $write = [$socket];
                $read = $except = [];
                
                $selectResult = socket_select($read, $write, $except, 1); // 1 second timeout
                
                if ($selectResult === 1) {
                    $error = socket_get_option($socket, SOL_SOCKET, SO_ERROR);
                    if ($error === 0) {
                        socket_set_block($socket);
                        return $socket;
                    }
                }
            }
            
            socket_close($socket);
            return null;
        }
        
        socket_set_block($socket);
        return $socket;
    }

    /**
     * Quick connection validation
     */
    private static function isConnectionValidQuick(array $connection): bool
    {
        if (!is_resource($connection['socket'])) {
            return false;
        }

        // Quick age check only
        $age = time() - $connection['created_at'];
        return $age < self::$config['connection_timeout'];
    }

    /**
     * Quick cache operations
     */
    private static function getCachedConnectionsQuick(string $poolKey): array
    {
        try {
            return Cache::get("shared_connections_{$poolKey}", []);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Quick connection claiming
     */
    private static function quickClaimConnection(string $poolKey, string $connId): bool
    {
        $lockKey = "qclaim_{$poolKey}_{$connId}";
        
        // Very short lock
        if (Cache::add($lockKey, self::$processId, 1)) {
            try {
                $connections = self::getCachedConnectionsQuick($poolKey);
                
                if (isset($connections[$connId]) && !$connections[$connId]['in_use']) {
                    $connections[$connId]['in_use'] = true;
                    $connections[$connId]['claimed_by'] = self::$processId;
                    
                    Cache::put("shared_connections_{$poolKey}", $connections, 30); // Short TTL
                    return true;
                }
            } finally {
                Cache::forget($lockKey);
            }
        }
        
        return false;
    }

    /**
     * Quick local connection storage
     */
    private static function storeLocalConnectionQuick(string $poolKey, array $connection): void
    {
        if (isset($connection['force_created']) && $connection['force_created']) {
            return; // Don't store force-created connections
        }
        
        if (!isset(self::$localConnections[$poolKey])) {
            self::$localConnections[$poolKey] = [];
        }

        // Limit local connections but allow more for high volume
        if (count(self::$localConnections[$poolKey]) >= self::$config['local_connection_limit']) {
            $oldest = array_key_first(self::$localConnections[$poolKey]);
            $oldConn = self::$localConnections[$poolKey][$oldest];
            if (is_resource($oldConn['socket'])) {
                socket_close($oldConn['socket']);
            }
            unset(self::$localConnections[$poolKey][$oldest]);
        }

        self::$localConnections[$poolKey][$connection['id']] = $connection;
    }

    /**
     * Quick connection release
     */
    private static function releaseConnectionQuick(string $poolKey, array $connection): void
    {
        if (isset($connection['claimed_from_shared']) && $connection['claimed_from_shared']) {
            try {
                $connections = self::getCachedConnectionsQuick($poolKey);
                
                if (isset($connections[$connection['id']])) {
                    $connections[$connection['id']]['in_use'] = false;
                    $connections[$connection['id']]['last_used'] = time();
                    unset($connections[$connection['id']]['claimed_by']);
                    
                    Cache::put("shared_connections_{$poolKey}", $connections, 30);
                }
            } catch (\Exception $e) {
                // Ignore release errors
            }
        }
    }

    /**
     * Quick cleanup for failed connections
     */
    private static function cleanupFailedConnectionQuick(string $poolKey, array $connection): void
    {
        // Remove from local storage
        if (isset(self::$localConnections[$poolKey][$connection['id']])) {
            unset(self::$localConnections[$poolKey][$connection['id']]);
        }

        // Close socket
        if (is_resource($connection['socket'])) {
            socket_close($connection['socket']);
        }
    }

    /**
     * Optimized send and receive for high volume
     */
    private static function sendAndReceive(array $connection, string $gpsData, string $vehicleId): array
    {
        $socket = $connection['socket'];
        $message = $gpsData . "\r";
        
        $bytesWritten = socket_write($socket, $message, strlen($message));
        
        if ($bytesWritten === false) {
            throw new \Exception("Write failed: " . socket_strerror(socket_last_error($socket)));
        }
        
        if ($bytesWritten === 0) {
            throw new \Exception("No bytes written");
        }

        // Very quick response read - don't wait long
        socket_set_nonblock($socket);
        $response = '';
        $startTime = microtime(true);
        
        while ((microtime(true) - $startTime) < 0.05) { // 50ms timeout
            $data = socket_read($socket, 1024);
            
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
            
            usleep(500); // 0.5ms sleep
        }
        
        socket_set_block($socket);

        return [
            'success' => true,
            'response' => trim($response),
            'bytes_written' => $bytesWritten,
            'vehicle_id' => $vehicleId
        ];
    }

    /**
     * Quick stats update (minimal database operations)
     */
    private static function updateStatsQuick(string $poolKey, string $action, bool $reused = false): void
    {
        // Skip stats in high volume mode to reduce database load
        // Or implement async stats collection
    }

    /**
     * Health check for monitoring
     */
    public static function healthCheck(string $host, int $port): array
    {
        return [
            'server_available' => self::isServerAvailable($host, $port),
            'local_connections' => count(self::$localConnections),
            'process_id' => self::$processId,
            'config' => self::$config
        ];
    }

    /**
     * Quick server availability check
     */
    private static function isServerAvailable(string $host, int $port): bool
    {
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);
        if ($connection) {
            fclose($connection);
            return true;
        }
        return false;
    }

    /**
     * Get current statistics
     */
    public static function getStats(): array
    {
        return [
            'process_id' => self::$processId,
            'local_connections_count' => array_sum(array_map('count', self::$localConnections)),
            'config' => self::$config
        ];
    }

    /**
     * Clean up all connections
     */
    public static function cleanup(): void
    {
        foreach (self::$localConnections as $poolKey => $connections) {
            foreach ($connections as $connection) {
                if (is_resource($connection['socket'])) {
                    socket_close($connection['socket']);
                }
            }
        }
        
        self::$localConnections = [];
    }
}