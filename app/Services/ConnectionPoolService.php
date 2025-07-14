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
            'max_connections_per_pool' => 3,
            'connection_timeout' => 300,
            'idle_timeout' => 30,  // Shorter timeout for testing
            'socket_timeout' => 3,
            'lock_timeout' => 5,   // Time to wait for locks
            'create_new_threshold' => 0.8 // Create new if 80% of pool is busy
        ], $config);

        self::$processId = (string) getmypid();
    }

    public static function sendGPSData(string $host, int $port, string $gpsData, string $vehicleId): array
    {
        if (empty(self::$config)) {
            self::init();
        }

        $poolKey = "{$host}:{$port}";
        
        // Step 1: Try local connection first (same process)
        $connection = self::getLocalConnection($poolKey);
        
        if (!$connection) {
            // Step 2: Try to claim a shared connection with proper locking
            $connection = self::claimSharedConnectionWithLock($poolKey);
        }
        
        if (!$connection) {
            // Step 3: Create new connection (with global pool management)
            $connection = self::createManagedConnection($host, $port, $poolKey);
        }
        
        if (!$connection) {
            self::updateStats($poolKey, 'connection_failed');
            return [
                'success' => false,
                'error' => 'Could not create or reuse connection',
                'vehicle_id' => $vehicleId,
                'process_id' => self::$processId
            ];
        }

        try {
            $result = self::sendAndReceive($connection, $gpsData, $vehicleId);
            
            // Mark connection as available again
            self::releaseConnectionProper($poolKey, $connection);
            
            // Store locally for immediate reuse
            self::storeLocalConnection($poolKey, $connection);
            
            self::updateStats($poolKey, 'success', $connection['reused']);
            
            return array_merge($result, [
                'reused' => $connection['reused'],
                'connection_id' => $connection['id'],
                'process_id' => self::$processId
            ]);
            
        } catch (\Exception $e) {
            self::cleanupFailedConnection($poolKey, $connection);
            self::updateStats($poolKey, 'send_failed');
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'vehicle_id' => $vehicleId,
                'connection_id' => $connection['id'] ?? 'unknown',
                'process_id' => self::$processId
            ];
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
                
                Log::channel('gps_pool')->info("REUSED LOCAL connection {$connId}", [
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
     * Claim shared connection with proper distributed locking
     */
    private static function claimSharedConnectionWithLock(string $poolKey): ?array
    {
        $globalLockKey = "global_pool_lock_{$poolKey}";
        $lockValue = self::$processId . '_' . time();
        
        // Try to acquire global pool lock
        if (!Cache::add($globalLockKey, $lockValue, self::$config['lock_timeout'])) {
            // Lock failed, but let's still try to find available connections
            usleep(rand(1000, 5000)); // Random backoff
        }
        
        try {
            $sharedConnections = self::getSharedConnections($poolKey);
            
            foreach ($sharedConnections as $connId => $connData) {
                // Skip if connection is in use or expired
                if ($connData['in_use'] || self::isSharedConnectionExpired($connData)) {
                    continue;
                }
                
                // Try to atomically claim this specific connection
                if (self::atomicClaimConnection($poolKey, $connId)) {
                    // Successfully claimed, now create socket
                    $socket = self::createNewSocket($connData['host'], $connData['port']);
                    
                    if ($socket) {
                        $connection = [
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
                        
                        Log::channel('gps_pool')->info("REUSED SHARED connection {$connId}", [
                            'process_id' => self::$processId,
                            'original_owner' => $connData['process_id'],
                            'use_count' => $connection['use_count']
                        ]);
                        
                        return $connection;
                    } else {
                        // Failed to create socket, release claim and remove from pool
                        self::removeSharedConnection($poolKey, $connId);
                    }
                }
            }
        } finally {
            // Release global lock if we own it
            $currentLock = Cache::get($globalLockKey);
            if ($currentLock === $lockValue) {
                Cache::forget($globalLockKey);
            }
        }

        return null;
    }

    /**
     * Create new connection with pool size management
     */
    private static function createManagedConnection(string $host, int $port, string $poolKey): ?array
    {
        // Check if we should create new connection based on pool state
        $sharedConnections = self::getSharedConnections($poolKey);
        $totalConnections = count($sharedConnections);
        $busyConnections = count(array_filter($sharedConnections, fn($c) => $c['in_use']));
        
        // If pool is near capacity and mostly busy, wait a bit and retry
        if ($totalConnections >= self::$config['max_connections_per_pool']) {
            $busyRatio = $totalConnections > 0 ? $busyConnections / $totalConnections : 0;
            
            if ($busyRatio > self::$config['create_new_threshold']) {
                // Wait briefly and try to claim again
                usleep(rand(10000, 50000)); // 10-50ms
                return self::claimSharedConnectionWithLock($poolKey);
            }
            
            // Remove oldest connection to make room
            $oldestKey = array_key_first($sharedConnections);
            if ($oldestKey) {
                self::removeSharedConnection($poolKey, $oldestKey);
            }
        }

        // Create new socket
        $socket = self::createNewSocket($host, $port);
        if (!$socket) {
            return null;
        }

        $connId = 'conn_' . uniqid('', true);
        $connection = [
            'id' => $connId,
            'socket' => $socket,
            'host' => $host,
            'port' => $port,
            'created_at' => time(),
            'last_used' => time(),
            'use_count' => 1,
            'reused' => false
        ];

        // Store in shared pool
        self::storeSharedConnection($poolKey, $connId, [
            'created_at' => $connection['created_at'],
            'host' => $host,
            'port' => $port,
            'process_id' => self::$processId,
            'last_used' => time(),
            'use_count' => 1,
            'in_use' => true // Mark as in use initially
        ]);

        self::updateStats($poolKey, 'created');
        
        Log::channel('gps_pool')->info("Created NEW connection {$connId} for {$poolKey}", [
            'process_id' => self::$processId,
            'total_pool_size' => $totalConnections + 1
        ]);

        return $connection;
    }

    /**
     * Store connection locally for immediate reuse
     */
    private static function storeLocalConnection(string $poolKey, array $connection): void
    {
        if (!isset(self::$localConnections[$poolKey])) {
            self::$localConnections[$poolKey] = [];
        }

        // Limit local connections per pool
        if (count(self::$localConnections[$poolKey]) >= 2) {
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
     * Release connection back to shared pool
     */
    private static function releaseConnectionProper(string $poolKey, array $connection): void
    {
        if (isset($connection['claimed_from_shared']) && $connection['claimed_from_shared']) {
            // This was claimed from shared pool, release it back
            $connections = self::getSharedConnections($poolKey);
            
            if (isset($connections[$connection['id']])) {
                $connections[$connection['id']]['in_use'] = false;
                $connections[$connection['id']]['last_used'] = time();
                $connections[$connection['id']]['use_count'] = $connection['use_count'];
                unset($connections[$connection['id']]['claimed_by']);
                unset($connections[$connection['id']]['claimed_at']);
                
                Cache::put("shared_connections_{$poolKey}", $connections, self::$config['connection_timeout']);
            }
        }
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
     * Check if shared connection data is expired
     */
    private static function isSharedConnectionExpired(array $connData): bool
    {
        $age = time() - $connData['created_at'];
        $idleTime = time() - $connData['last_used'];
        
        return $age > self::$config['connection_timeout'] || 
               $idleTime > self::$config['idle_timeout'];
    }

    /**
     * Atomic operation to claim a specific connection
     */
    private static function atomicClaimConnection(string $poolKey, string $connId): bool
    {
        $lockKey = "claim_lock_{$poolKey}_{$connId}";
        
        if (Cache::add($lockKey, self::$processId, 2)) {
            try {
                $connections = self::getSharedConnections($poolKey);
                
                if (isset($connections[$connId]) && !$connections[$connId]['in_use']) {
                    $connections[$connId]['in_use'] = true;
                    $connections[$connId]['claimed_by'] = self::$processId;
                    $connections[$connId]['claimed_at'] = time();
                    
                    Cache::put("shared_connections_{$poolKey}", $connections, self::$config['connection_timeout']);
                    return true;
                }
            } finally {
                Cache::forget($lockKey);
            }
        }
        
        return false;
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
        self::removeSharedConnection($poolKey, $connection['id']);

        // Close socket
        if (is_resource($connection['socket'])) {
            socket_close($connection['socket']);
        }
    }

    // Keep all your existing helper methods: getSharedConnections, storeSharedConnection, 
    // removeSharedConnection, createNewSocket, validateConnection, sendAndReceive, updateStats, etc.
    
    /**
     * Get shared connections from cache/storage
     */
    private static function getSharedConnections(string $poolKey): array
    {
        try {
            return Cache::get("shared_connections_{$poolKey}", []);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Store connection in shared storage
     */
    private static function storeSharedConnection(string $poolKey, string $connId, array $connData): void
    {
        try {
            $connections = self::getSharedConnections($poolKey);
            $connections[$connId] = array_merge($connData, [
                'last_used' => time(),
                'in_use' => false
            ]);
            
            Cache::put("shared_connections_{$poolKey}", $connections, self::$config['connection_timeout']);
        } catch (\Exception $e) {
            // Ignore cache errors
        }
    }

    /**
     * Remove connection from shared storage
     */
    private static function removeSharedConnection(string $poolKey, string $connId): void
    {
        try {
            $connections = self::getSharedConnections($poolKey);
            unset($connections[$connId]);
            Cache::put("shared_connections_{$poolKey}", $connections, self::$config['connection_timeout']);
        } catch (\Exception $e) {
            // Ignore cache errors
        }
    }

    /**
     * Create new socket connection
     */
    private static function createNewSocket(string $host, int $port)
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
        if ($socket === false) {
            return null;
        }

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

        // Quick non-blocking read
        socket_set_nonblock($socket);
        $response = '';
        $startTime = microtime(true);
        
        while ((microtime(true) - $startTime) < 0.2) {
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
            
            usleep(1000);
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
            // Ignore stats errors
        }
    }
}