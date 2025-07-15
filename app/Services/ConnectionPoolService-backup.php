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
            'local_connection_limit' => 10,         // Much more local connections for reuse
            'force_create_after_attempts' => 4,     // Try reuse longer before force create
            'aggressive_mode' => true,              // Always try to create connections
            'always_store_connections' => true,     // Store all connections for reuse
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
                
                // IMPORTANT: Only store if connection is still valid after use
                if (is_resource($connection['socket'])) {
                    self::storeLocalConnectionQuick($poolKey, $connection);
                    
                    Log::channel('gps_pool')->debug("Connection stored after successful use", [
                        'connection_id' => $connection['id'],
                        'pool_key' => $poolKey,
                        'process_id' => self::$processId
                    ]);
                } else {
                    Log::channel('gps_pool')->debug("Connection NOT stored - socket was closed", [
                        'connection_id' => $connection['id'],
                        'pool_key' => $poolKey,
                        'process_id' => self::$processId
                    ]);
                }
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
     * Aggressive connection strategy - prioritize reuse first
     */
    private static function getConnectionAggressive(string $poolKey, string $host, int $port, int $attempt): ?array
    {
        // ALWAYS try local connection first (all attempts)
        $connection = self::getLocalConnectionQuick($poolKey);
        if ($connection) {
            Log::channel('gps_pool')->debug("REUSED LOCAL connection on attempt {$attempt}", [
                'connection_id' => $connection['id'],
                'use_count' => $connection['use_count'],
                'process_id' => self::$processId
            ]);
            return $connection;
        }
        
        // Attempts 1-3: Try shared pool
        if ($attempt <= 3) {
            $connection = self::getSharedConnectionMinimal($poolKey, $host, $port);
            if ($connection) {
                Log::channel('gps_pool')->debug("REUSED SHARED connection on attempt {$attempt}", [
                    'connection_id' => $connection['id'],
                    'process_id' => self::$processId
                ]);
                return $connection;
            }
        }
        
        // Final attempts: Force create but STORE for reuse
        $connection = self::forceCreateConnectionOptimized($host, $port, $poolKey);
        if ($connection) {
            Log::channel('gps_pool')->info("CREATED NEW connection on attempt {$attempt}", [
                'connection_id' => $connection['id'],
                'process_id' => self::$processId
            ]);
        }
        return $connection;
    }

    /**
     * Enhanced local connection retrieval with debugging
     */
    private static function getLocalConnectionQuick(string $poolKey): ?array
    {
        if (!isset(self::$localConnections[$poolKey])) {
            Log::channel('gps_pool')->debug("No local connections exist for pool", [
                'pool_key' => $poolKey,
                'process_id' => self::$processId,
                'total_pools' => count(self::$localConnections)
            ]);
            return null;
        }

        $connectionCount = count(self::$localConnections[$poolKey]);
        Log::channel('gps_pool')->debug("Checking local connections", [
            'pool_key' => $poolKey,
            'connection_count' => $connectionCount,
            'process_id' => self::$processId,
            'connection_ids' => array_keys(self::$localConnections[$poolKey])
        ]);

        foreach (self::$localConnections[$poolKey] as $connId => $connection) {
            Log::channel('gps_pool')->debug("Validating connection", [
                'connection_id' => $connId,
                'socket_resource' => is_resource($connection['socket']),
                'created_at' => $connection['created_at'],
                'last_used' => $connection['last_used'],
                'age' => time() - $connection['created_at'],
                'idle_time' => time() - $connection['last_used'],
                'process_id' => self::$processId
            ]);
            
            if (self::isConnectionValidQuick($connection)) {
                $connection['reused'] = true;
                $connection['use_count'] = ($connection['use_count'] ?? 0) + 1;
                $connection['last_used'] = time();
                
                self::$localConnections[$poolKey][$connId] = $connection;
                
                Log::channel('gps_pool')->info("SUCCESS: REUSED LOCAL connection", [
                    'connection_id' => $connId,
                    'use_count' => $connection['use_count'],
                    'pool_key' => $poolKey,
                    'process_id' => self::$processId
                ]);
                
                return $connection;
            } else {
                // Remove invalid connection
                if (is_resource($connection['socket'])) {
                    socket_close($connection['socket']);
                }
                unset(self::$localConnections[$poolKey][$connId]);
                
                Log::channel('gps_pool')->debug("Removed invalid local connection", [
                    'connection_id' => $connId,
                    'pool_key' => $poolKey,
                    'process_id' => self::$processId
                ]);
            }
        }

        Log::channel('gps_pool')->debug("No valid local connections found after validation", [
            'pool_key' => $poolKey,
            'process_id' => self::$processId
        ]);

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
     * Enhanced connection validation with detailed socket checking
     */
    private static function isConnectionValidQuick(array $connection): bool
    {
        if (!is_resource($connection['socket'])) {
            Log::channel('gps_pool')->debug("Connection invalid - not a resource", [
                'connection_id' => $connection['id'],
                'socket_type' => gettype($connection['socket'])
            ]);
            return false;
        }

        // Check if socket is still connected
        $socketInfo = socket_get_option($connection['socket'], SOL_SOCKET, SO_TYPE);
        if ($socketInfo === false) {
            Log::channel('gps_pool')->debug("Connection invalid - cannot get socket info", [
                'connection_id' => $connection['id'],
                'error' => socket_strerror(socket_last_error($connection['socket']))
            ]);
            return false;
        }

        // Check socket error status
        $error = socket_get_option($connection['socket'], SOL_SOCKET, SO_ERROR);
        if ($error !== 0) {
            Log::channel('gps_pool')->debug("Connection invalid - socket error", [
                'connection_id' => $connection['id'],
                'error' => $error,
                'error_string' => socket_strerror($error)
            ]);
            return false;
        }

        // More lenient age check for better reuse
        $age = time() - $connection['created_at'];
        if ($age > self::$config['connection_timeout']) {
            Log::channel('gps_pool')->debug("Connection invalid - too old", [
                'connection_id' => $connection['id'],
                'age' => $age,
                'timeout' => self::$config['connection_timeout']
            ]);
            return false;
        }

        // Check idle time
        $idleTime = time() - $connection['last_used'];
        if ($idleTime > self::$config['idle_timeout']) {
            Log::channel('gps_pool')->debug("Connection invalid - idle too long", [
                'connection_id' => $connection['id'],
                'idle_time' => $idleTime,
                'idle_timeout' => self::$config['idle_timeout']
            ]);
            return false;
        }

        Log::channel('gps_pool')->debug("Connection is valid", [
            'connection_id' => $connection['id'],
            'age' => $age,
            'idle_time' => $idleTime
        ]);

        return true;
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
     * Store ALL connections for reuse (including force-created)
     */
    private static function storeLocalConnectionQuick(string $poolKey, array $connection): void
    {
        // Store ALL connections for maximum reuse
        if (!isset(self::$localConnections[$poolKey])) {
            self::$localConnections[$poolKey] = [];
        }

        // Increased limit for better reuse
        if (count(self::$localConnections[$poolKey]) >= self::$config['local_connection_limit']) {
            // Remove oldest connection to make room
            $oldest = array_key_first(self::$localConnections[$poolKey]);
            $oldConn = self::$localConnections[$poolKey][$oldest];
            if (is_resource($oldConn['socket'])) {
                socket_close($oldConn['socket']);
            }
            unset(self::$localConnections[$poolKey][$oldest]);
            
            Log::channel('gps_pool')->debug("Replaced oldest local connection", [
                'pool_key' => $poolKey,
                'old_connection_id' => $oldest,
                'process_id' => self::$processId
            ]);
        }

        // Store connection for reuse
        self::$localConnections[$poolKey][$connection['id']] = $connection;
        
        Log::channel('gps_pool')->debug("STORED connection for reuse", [
            'connection_id' => $connection['id'],
            'pool_key' => $poolKey,
            'local_pool_size' => count(self::$localConnections[$poolKey]),
            'force_created' => $connection['force_created'] ?? false,
            'process_id' => self::$processId
        ]);
    }

    /**
     * Keep connections alive - don't close after use for force-created connections
     */
    private static function releaseConnectionQuick(string $poolKey, array $connection): void
    {
        // For force-created connections, keep socket open for reuse
        if (isset($connection['force_created']) && $connection['force_created']) {
            Log::channel('gps_pool')->debug("Keeping force-created connection open for reuse", [
                'connection_id' => $connection['id'],
                'pool_key' => $poolKey,
                'process_id' => self::$processId
            ]);
            return; // Don't release/close force-created connections
        }
        
        // Only release shared pool connections
        if (isset($connection['claimed_from_shared']) && $connection['claimed_from_shared']) {
            try {
                $connections = self::getCachedConnectionsQuick($poolKey);
                
                if (isset($connections[$connection['id']])) {
                    $connections[$connection['id']]['in_use'] = false;
                    $connections[$connection['id']]['last_used'] = time();
                    unset($connections[$connection['id']]['claimed_by']);
                    
                    Cache::put("shared_connections_{$poolKey}", $connections, 30);
                    
                    Log::channel('gps_pool')->debug("Released shared connection back to pool", [
                        'connection_id' => $connection['id'],
                        'pool_key' => $poolKey,
                        'process_id' => self::$processId
                    ]);
                }
            } catch (\Exception $e) {
                Log::channel('gps_pool')->warning("Failed to release shared connection", [
                    'connection_id' => $connection['id'],
                    'error' => $e->getMessage(),
                    'process_id' => self::$processId
                ]);
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
     * Optimized send and receive for high volume - DON'T close sockets
     */
    private static function sendAndReceive(array $connection, string $gpsData, string $vehicleId): array
    {
        $socket = $connection['socket'];
        $message = $gpsData . "\r";
        
        Log::channel('gps_pool')->debug("Starting sendAndReceive", [
            'connection_id' => $connection['id'],
            'socket_valid_before' => is_resource($socket),
            'process_id' => self::$processId
        ]);
        
        $bytesWritten = socket_write($socket, $message, strlen($message));
        
        if ($bytesWritten === false) {
            $error = socket_strerror(socket_last_error($socket));
            Log::channel('gps_pool')->error("Socket write failed", [
                'connection_id' => $connection['id'],
                'error' => $error,
                'socket_valid_after_write' => is_resource($socket),
                'process_id' => self::$processId
            ]);
            throw new \Exception("Write failed: " . $error);
        }
        
        if ($bytesWritten === 0) {
            Log::channel('gps_pool')->error("No bytes written", [
                'connection_id' => $connection['id'],
                'socket_valid_after_write' => is_resource($socket),
                'process_id' => self::$processId
            ]);
            throw new \Exception("No bytes written");
        }

        Log::channel('gps_pool')->debug("Data written successfully", [
            'connection_id' => $connection['id'],
            'bytes_written' => $bytesWritten,
            'socket_valid_after_write' => is_resource($socket),
            'process_id' => self::$processId
        ]);

        // Very quick response read - don't wait long and DON'T use socket_set_nonblock
        $response = '';
        $startTime = microtime(true);
        
        // Try to read response with very short timeout
        $read = [$socket];
        $write = $except = [];
        $timeout_sec = 0;
        $timeout_usec = 50000; // 50ms
        
        $selectResult = socket_select($read, $write, $except, $timeout_sec, $timeout_usec);
        
        if ($selectResult > 0 && in_array($socket, $read)) {
            $data = socket_read($socket, 1024);
            if ($data !== false && $data !== '') {
                $response = $data;
            }
        }
        
        Log::channel('gps_pool')->debug("Finished sendAndReceive", [
            'connection_id' => $connection['id'],
            'socket_valid_after_read' => is_resource($socket),
            'response_length' => strlen($response),
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'process_id' => self::$processId
        ]);

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
     * Get current statistics with detailed connection info
     */
    public static function getStats(): array
    {
        $connectionDetails = [];
        foreach (self::$localConnections as $poolKey => $connections) {
            $connectionDetails[$poolKey] = [];
            foreach ($connections as $connId => $connection) {
                $connectionDetails[$poolKey][$connId] = [
                    'id' => $connection['id'],
                    'created_at' => $connection['created_at'],
                    'last_used' => $connection['last_used'],
                    'use_count' => $connection['use_count'] ?? 0,
                    'age' => time() - $connection['created_at'],
                    'idle_time' => time() - $connection['last_used'],
                    'socket_valid' => is_resource($connection['socket']),
                    'force_created' => $connection['force_created'] ?? false
                ];
            }
        }
        
        return [
            'process_id' => self::$processId,
            'local_connections_count' => array_sum(array_map('count', self::$localConnections)),
            'connection_details' => $connectionDetails,
            'config' => self::$config
        ];
    }

    /**
     * Debug method to test socket behavior
     */
    public static function testSocketBehavior(string $host = '10.21.14.8', int $port = 1403): array
    {
        $result = [
            'process_id' => self::$processId,
            'test_time' => date('Y-m-d H:i:s'),
            'steps' => []
        ];
        
        // Step 1: Create socket
        $socket = self::createSocketFast($host, $port);
        $result['steps']['create'] = [
            'success' => $socket !== null,
            'socket_valid' => $socket ? is_resource($socket) : false
        ];
        
        if (!$socket) {
            return $result;
        }
        
        // Step 2: Test write
        $testMessage = '$test,1,1.0,2.0,0,0,0,5,0,0,10,0,0,TEST' . "\r";
        $bytesWritten = socket_write($socket, $testMessage, strlen($testMessage));
        $result['steps']['write'] = [
            'success' => $bytesWritten !== false,
            'bytes_written' => $bytesWritten,
            'socket_valid_after_write' => is_resource($socket)
        ];
        
        // Step 3: Test read attempt (quick)
        $read = [$socket];
        $write = $except = [];
        $selectResult = socket_select($read, $write, $except, 0, 50000); // 50ms
        $response = '';
        
        if ($selectResult > 0 && in_array($socket, $read)) {
            $data = socket_read($socket, 1024);
            if ($data !== false && $data !== '') {
                $response = $data;
            }
        }
        
        $result['steps']['read'] = [
            'select_result' => $selectResult,
            'response_length' => strlen($response),
            'socket_valid_after_read' => is_resource($socket)
        ];
        
        // Step 4: Test socket status
        if (is_resource($socket)) {
            $error = socket_get_option($socket, SOL_SOCKET, SO_ERROR);
            $result['steps']['status'] = [
                'socket_error' => $error,
                'socket_valid' => is_resource($socket)
            ];
        }
        
        // Step 5: Keep socket open (don't close)
        $result['steps']['final'] = [
            'socket_kept_open' => is_resource($socket),
            'action' => 'socket not closed for testing'
        ];
        
        // Don't close the socket to test if it survives
        // socket_close($socket);
        
        return $result;
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