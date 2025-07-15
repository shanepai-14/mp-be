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
    private static int $consecutiveFailures = 0;
    private static bool $emergencyMode = false;
    
    public static function init(array $config = []): void
    {
        self::$config = array_merge([
            'max_connections_per_pool' => 5,        // Increased from 3
            'connection_timeout' => 300,
            'idle_timeout' => 60,                   // Increased from 30
            'socket_timeout' => 5,                  // Increased from 3
            'lock_timeout' => 2,                    // Reduced from 5
            'create_new_threshold' => 0.7,          // Reduced from 0.8
            'max_retry_attempts' => 3,              // Increased from 2
            'retry_delay_ms' => 50,                 // Reduced from 100
            'emergency_threshold' => 10,            // Consecutive failures before emergency mode
            'emergency_cooldown' => 30,             // Seconds to stay in emergency mode
            'force_new_connection_ratio' => 0.3,    // 30% of requests bypass pool in emergency
        ], $config);

        self::$processId = (string) getmypid();
        
        // Check if we should be in emergency mode
        self::checkEmergencyMode();
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
        
        // Emergency mode: bypass pool for some requests
        if (self::$emergencyMode && mt_rand(1, 100) <= (self::$config['force_new_connection_ratio'] * 100)) {
            return self::sendViaDirectConnection($host, $port, $gpsData, $vehicleId);
        }
        
        while ($attempts < self::$config['max_retry_attempts']) {
            $attempts++;
            $connection = null;
            
            try {
                // Progressive fallback strategy
                $connection = self::getConnectionProgressive($poolKey, $host, $port, $attempts);
                
                if (!$connection) {
                    $lastError = 'Could not create or reuse connection after all fallback attempts';
                    
                    if ($attempts < self::$config['max_retry_attempts']) {
                        // Exponential backoff with jitter
                        $delay = self::$config['retry_delay_ms'] * pow(2, $attempts - 1);
                        $jitter = mt_rand(0, $delay / 2);
                        usleep(($delay + $jitter) * 1000);
                        continue;
                    }
                    break;
                }

                // Test connection before use
                if (!self::testConnection($connection)) {
                    self::cleanupFailedConnection($poolKey, $connection);
                    $lastError = 'Connection test failed';
                    continue;
                }

                $result = self::sendAndReceive($connection, $gpsData, $vehicleId);
                
                // Success - reset failure counter
                self::$consecutiveFailures = 0;
                self::$emergencyMode = false;
                
                // Clean up and return
                self::releaseConnectionProper($poolKey, $connection);
                self::storeLocalConnection($poolKey, $connection);
                self::updateStats($poolKey, 'success', $connection['reused']);
                
                return array_merge($result, [
                    'reused' => $connection['reused'],
                    'connection_id' => $connection['id'],
                    'process_id' => self::$processId,
                    'attempts' => $attempts,
                    'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    'emergency_mode' => self::$emergencyMode
                ]);
                
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                
                if ($connection) {
                    self::cleanupFailedConnection($poolKey, $connection);
                }
                
                Log::channel('gps_pool')->warning("GPS send attempt {$attempts} failed", [
                    'vehicle_id' => $vehicleId,
                    'error' => $lastError,
                    'process_id' => self::$processId,
                    'pool_key' => $poolKey
                ]);
                
                if ($attempts < self::$config['max_retry_attempts']) {
                    $delay = self::$config['retry_delay_ms'] * pow(2, $attempts - 1);
                    $jitter = mt_rand(0, $delay / 2);
                    usleep(($delay + $jitter) * 1000);
                    continue;
                }
            }
        }
        
        // All attempts failed - increment failure counter
        self::$consecutiveFailures++;
        self::checkEmergencyMode();
        
        self::updateStats($poolKey, 'connection_failed');
        
        return [
            'success' => false,
            'error' => $lastError,
            'vehicle_id' => $vehicleId,
            'process_id' => self::$processId,
            'attempts' => $attempts,
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'emergency_mode' => self::$emergencyMode,
            'consecutive_failures' => self::$consecutiveFailures
        ];
    }

    /**
     * Direct connection bypass for emergency mode
     */
    private static function sendViaDirectConnection(string $host, int $port, string $gpsData, string $vehicleId): array
    {
        $startTime = microtime(true);
        
        try {
            $socket = self::createDirectSocket($host, $port);
            if (!$socket) {
                return [
                    'success' => false,
                    'error' => 'Direct connection failed',
                    'vehicle_id' => $vehicleId,
                    'process_id' => self::$processId,
                    'emergency_mode' => true,
                    'direct_connection' => true
                ];
            }

            $connection = [
                'id' => 'direct_' . uniqid(),
                'socket' => $socket,
                'reused' => false,
                'direct' => true
            ];

            $result = self::sendAndReceive($connection, $gpsData, $vehicleId);
            
            // Close direct connection immediately
            socket_close($socket);
            
            return array_merge($result, [
                'reused' => false,
                'connection_id' => $connection['id'],
                'process_id' => self::$processId,
                'emergency_mode' => true,
                'direct_connection' => true,
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'vehicle_id' => $vehicleId,
                'process_id' => self::$processId,
                'emergency_mode' => true,
                'direct_connection' => true
            ];
        }
    }

    /**
     * Progressive connection strategy based on attempt number
     */
    private static function getConnectionProgressive(string $poolKey, string $host, int $port, int $attempt): ?array
    {
        switch ($attempt) {
            case 1:
                // First attempt: try local connection
                return self::getLocalConnection($poolKey);
                
            case 2:
                // Second attempt: try shared connection (fast)
                return self::claimSharedConnectionFast($poolKey);
                
            case 3:
            default:
                // Final attempt: force create new connection
                return self::forceCreateConnection($host, $port, $poolKey);
        }
    }

    /**
     * Fast shared connection claiming without heavy locking
     */
    private static function claimSharedConnectionFast(string $poolKey): ?array
    {
        try {
            $sharedConnections = self::getSharedConnectionsQuick($poolKey);
            
            foreach ($sharedConnections as $connId => $connData) {
                // Quick check - skip if obviously bad
                if ($connData['in_use'] || 
                    (time() - $connData['last_used']) > self::$config['idle_timeout'] ||
                    (time() - $connData['created_at']) > self::$config['connection_timeout']) {
                    continue;
                }
                
                // Try to atomically claim (with short timeout)
                if (self::quickClaimConnection($poolKey, $connId)) {
                    $socket = self::createDirectSocket($connData['host'], $connData['port']);
                    
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
                    } else {
                        // Failed to create socket, remove from pool
                        self::removeSharedConnectionQuick($poolKey, $connId);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::channel('gps_pool')->warning("Fast shared connection claim failed", [
                'error' => $e->getMessage(),
                'pool_key' => $poolKey
            ]);
        }

        return null;
    }

    /**
     * Quick connection claiming with minimal locking
     */
    private static function quickClaimConnection(string $poolKey, string $connId): bool
    {
        $lockKey = "qclaim_{$poolKey}_{$connId}";
        
        // Very short lock timeout
        if (Cache::add($lockKey, self::$processId, 1)) {
            try {
                $connections = self::getSharedConnectionsQuick($poolKey);
                
                if (isset($connections[$connId]) && !$connections[$connId]['in_use']) {
                    $connections[$connId]['in_use'] = true;
                    $connections[$connId]['claimed_by'] = self::$processId;
                    $connections[$connId]['claimed_at'] = time();
                    
                    // Quick cache update
                    Cache::put("shared_connections_{$poolKey}", $connections, 60);
                    return true;
                }
            } finally {
                Cache::forget($lockKey);
            }
        }
        
        return false;
    }

    /**
     * Quick cache operations with fallback
     */
    private static function getSharedConnectionsQuick(string $poolKey): array
    {
        try {
            return Cache::get("shared_connections_{$poolKey}", []);
        } catch (\Exception $e) {
            return [];
        }
    }

    private static function removeSharedConnectionQuick(string $poolKey, string $connId): void
    {
        try {
            $connections = self::getSharedConnectionsQuick($poolKey);
            unset($connections[$connId]);
            Cache::put("shared_connections_{$poolKey}", $connections, 60);
        } catch (\Exception $e) {
            // Ignore errors
        }
    }

    /**
     * Create direct socket connection
     */
    private static function createDirectSocket(string $host, int $port)
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
        if ($socket === false) {
            return null;
        }

        // Set non-blocking for connect
        socket_set_nonblock($socket);
        
        // Attempt connection
        $result = socket_connect($socket, $host, $port);
        
        if ($result === false) {
            $error = socket_last_error($socket);
            
            if ($error === SOCKET_EINPROGRESS || $error === SOCKET_EALREADY || $error === SOCKET_EWOULDBLOCK) {
                // Connection in progress, wait for completion
                $write = [$socket];
                $read = $except = [];
                
                $selectResult = socket_select($read, $write, $except, self::$config['socket_timeout']);
                
                if ($selectResult === 1) {
                    // Check if connection succeeded
                    $error = socket_get_option($socket, SOL_SOCKET, SO_ERROR);
                    if ($error === 0) {
                        socket_set_block($socket);
                        self::setSocketOptions($socket);
                        return $socket;
                    }
                }
            }
            
            socket_close($socket);
            return null;
        }
        
        // Connection succeeded immediately
        socket_set_block($socket);
        self::setSocketOptions($socket);
        return $socket;
    }

    /**
     * Set socket options
     */
    private static function setSocketOptions($socket): void
    {
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);
        socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, [
            "sec" => self::$config['socket_timeout'], 
            "usec" => 0
        ]);
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, [
            "sec" => self::$config['socket_timeout'], 
            "usec" => 0
        ]);
    }

    /**
     * Test connection before use
     */
    private static function testConnection(array $connection): bool
    {
        if (!is_resource($connection['socket'])) {
            return false;
        }

        // Quick socket status check
        $error = socket_get_option($connection['socket'], SOL_SOCKET, SO_ERROR);
        return $error === 0;
    }

    /**
     * Check and set emergency mode
     */
    private static function checkEmergencyMode(): void
    {
        $emergencyKey = "emergency_mode_" . self::$processId;
        
        if (self::$consecutiveFailures >= self::$config['emergency_threshold']) {
            self::$emergencyMode = true;
            Cache::put($emergencyKey, true, self::$config['emergency_cooldown']);
            
            Log::channel('gps_pool')->critical("Entering emergency mode", [
                'consecutive_failures' => self::$consecutiveFailures,
                'process_id' => self::$processId
            ]);
        } else {
            self::$emergencyMode = Cache::get($emergencyKey, false);
        }
    }

    /**
     * Get local connection (same process reuse)
     */
    private static function getLocalConnection(string $poolKey): ?array
    {
        if (!isset(self::$localConnections[$poolKey])) {
            return null;
        }

        foreach (self::$localConnections[$poolKey] as $connId => $connection) {
            if (self::isConnectionStillValid($connection)) {
                $connection['reused'] = true;
                $connection['use_count']++;
                $connection['last_used'] = time();
                
                self::$localConnections[$poolKey][$connId] = $connection;
                
                Log::channel('gps_pool')->debug("REUSED LOCAL connection {$connId}", [
                    'process_id' => self::$processId,
                    'use_count' => $connection['use_count']
                ]);
                
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
     * Force create connection bypassing pool management
     */
    private static function forceCreateConnection(string $host, int $port, string $poolKey): ?array
    {
        $socket = self::createDirectSocket($host, $port);
        if (!$socket) {
            return null;
        }

        $connId = 'force_conn_' . uniqid('', true);
        
        Log::channel('gps_pool')->info("FORCE created connection {$connId} for {$poolKey}", [
            'process_id' => self::$processId
        ]);
        
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
     * Check if connection is still valid for reuse
     */
    private static function isConnectionStillValid(array $connection): bool
    {
        if (!is_resource($connection['socket'])) {
            return false;
        }

        // Check socket status
        $error = socket_get_option($connection['socket'], SOL_SOCKET, SO_ERROR);
        if ($error !== 0) {
            return false;
        }

        // Check age
        $age = time() - $connection['created_at'];
        if ($age > self::$config['connection_timeout']) {
            return false;
        }

        // Check idle time
        $idleTime = time() - $connection['last_used'];
        if ($idleTime > self::$config['idle_timeout']) {
            return false;
        }

        return true;
    }

    /**
     * Send GPS data and receive response
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

        // Quick non-blocking read with shorter timeout
        socket_set_nonblock($socket);
        $response = '';
        $startTime = microtime(true);
        $timeout = 0.1; // Reduced timeout to 100ms
        
        while ((microtime(true) - $startTime) < $timeout) {
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
            
            usleep(1000); // 1ms sleep
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
     * Release connection back to shared pool
     */
    private static function releaseConnectionProper(string $poolKey, array $connection): void
    {
        if (isset($connection['claimed_from_shared']) && $connection['claimed_from_shared']) {
            try {
                $connections = self::getSharedConnectionsQuick($poolKey);
                
                if (isset($connections[$connection['id']])) {
                    $connections[$connection['id']]['in_use'] = false;
                    $connections[$connection['id']]['last_used'] = time();
                    $connections[$connection['id']]['use_count'] = $connection['use_count'];
                    unset($connections[$connection['id']]['claimed_by']);
                    unset($connections[$connection['id']]['claimed_at']);
                    
                    Cache::put("shared_connections_{$poolKey}", $connections, 60);
                }
            } catch (\Exception $e) {
                // Ignore release errors
            }
        }
    }

    /**
     * Clean up failed connection
     */
    private static function cleanupFailedConnection(string $poolKey, array $connection): void
    {
        // Remove from local storage
        if (isset(self::$localConnections[$poolKey][$connection['id']])) {
            unset(self::$localConnections[$poolKey][$connection['id']]);
        }

        // Remove from shared storage
        self::removeSharedConnectionQuick($poolKey, $connection['id']);

        // Close socket
        if (is_resource($connection['socket'])) {
            socket_close($connection['socket']);
        }
    }

    /**
     * Update statistics
     */
    private static function updateStats(string $poolKey, string $action, bool $reused = false): void
    {
        try {
            $stat = ConnectionPoolStat::firstOrCreate(
                [
                    'pool_key' => $poolKey,
                    'process_id' => self::$processId
                ],
                [
                    'created' => 0,
                    'success' => 0,
                    'reused' => 0,
                    'send_failed' => 0,
                    'connection_failed' => 0,
                    'last_action' => $action,
                    'last_action_time' => now()
                ]
            );
            
            $stat->increment($action);
            
            if ($reused) {
                $stat->increment('reused');
            }
            
            $stat->update([
                'last_action' => $action,
                'last_action_time' => now()
            ]);
            
        } catch (\Exception $e) {
            // Ignore stats errors to prevent cascading failures
        }
    }
    
    /**
     * Simplified local connection storage
     */
    private static function storeLocalConnection(string $poolKey, array $connection): void
    {
        if (isset($connection['direct']) && $connection['direct']) {
            return; // Don't store direct connections
        }
        
        if (!isset(self::$localConnections[$poolKey])) {
            self::$localConnections[$poolKey] = [];
        }

        // Limit and clean local connections
        if (count(self::$localConnections[$poolKey]) >= 1) {
            foreach (self::$localConnections[$poolKey] as $connId => $oldConn) {
                if (is_resource($oldConn['socket'])) {
                    socket_close($oldConn['socket']);
                }
            }
            self::$localConnections[$poolKey] = [];
        }

        self::$localConnections[$poolKey][$connection['id']] = $connection;
    }

    /**
     * Simplified local connection storage
     */
    private static function storeLocalConnection(string $poolKey, array $connection): void
    {
        if (isset($connection['direct']) && $connection['direct']) {
            return; // Don't store direct connections
        }
        
        if (!isset(self::$localConnections[$poolKey])) {
            self::$localConnections[$poolKey] = [];
        }

        // Limit and clean local connections
        if (count(self::$localConnections[$poolKey]) >= 1) {
            foreach (self::$localConnections[$poolKey] as $connId => $oldConn) {
                if (is_resource($oldConn['socket'])) {
                    socket_close($oldConn['socket']);
                }
            }
            self::$localConnections[$poolKey] = [];
        }

        self::$localConnections[$poolKey][$connection['id']] = $connection;
    }

    /**
     * Health check method for monitoring
     */
    public static function healthCheck(string $host, int $port): array
    {
        return [
            'server_available' => self::isTargetServerAvailable($host, $port),
            'cache_accessible' => self::testCacheAccess(),
            'local_connections' => count(self::$localConnections),
            'process_id' => self::$processId,
            'emergency_mode' => self::$emergencyMode,
            'consecutive_failures' => self::$consecutiveFailures
        ];
    }

    /**
     * Test cache accessibility
     */
    private static function testCacheAccess(): bool
    {
        try {
            $testKey = 'cache_test_' . self::$processId;
            Cache::put($testKey, 'test', 1);
            $result = Cache::get($testKey);
            Cache::forget($testKey);
            return $result === 'test';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if target server is available
     */
    private static function isTargetServerAvailable(string $host, int $port): bool
    {
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);
        if ($connection) {
            fclose($connection);
            return true;
        }
        
        return false;
    }

    /**
     * Get connection statistics for monitoring
     */
    public static function getStats(): array
    {
        return [
            'process_id' => self::$processId,
            'emergency_mode' => self::$emergencyMode,
            'consecutive_failures' => self::$consecutiveFailures,
            'local_connections_count' => array_sum(array_map('count', self::$localConnections)),
            'config' => self::$config
        ];
    }

    /**
     * Reset emergency mode (for manual recovery)
     */
    public static function resetEmergencyMode(): void
    {
        self::$emergencyMode = false;
        self::$consecutiveFailures = 0;
        
        $emergencyKey = "emergency_mode_" . self::$processId;
        Cache::forget($emergencyKey);
        
        Log::channel('gps_pool')->info("Emergency mode manually reset", [
            'process_id' => self::$processId
        ]);
    }

    /**
     * Clean up all local connections (for shutdown/restart)
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
        
        Log::channel('gps_pool')->info("Connection pool cleanup completed", [
            'process_id' => self::$processId
        ]);
    }
}