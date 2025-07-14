<?php

namespace App\Services;

use App\Models\ConnectionPoolStat;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ConnectionPoolService
{
    private static array $config = [];
    private static string $processId = '';
    
    /**
     * Initialize the service
     */
    public static function init(array $config = []): void
    {
        self::$config = array_merge([
            'max_connections_per_pool' => 5,
            'connection_timeout' => 300,      // 5 minutes
            'idle_timeout' => 120,            // 2 minutes
            'socket_timeout' => 3,
            'connection_check_interval' => 10 // Check every 10 seconds
        ], $config);

        self::$processId = (string) getmypid();
    }

    /**
     * Send GPS data with persistent connection management
     */
   public static function sendGPSData(string $host, int $port, string $gpsData, string $vehicleId): array
    {
        if (empty(self::$config)) {
            self::init();
        }

        $poolKey = "{$host}:{$port}";
        $connection = null;
        
        // First, try to reuse a local connection in this process
        $connection = self::getLocalConnection($poolKey);
        
        if (!$connection) {
            // Try to claim a shared connection from cache
            $connection = self::claimSharedConnection($poolKey);
        }
        
        if (!$connection) {
            // Create new connection
            $connection = self::createNewConnection($host, $port, $poolKey);
        }
        
        if (!$connection) {
            self::updateStats($poolKey, 'connection_failed');
            return [
                'success' => false,
                'error' => 'Could not create or reuse connection',
                'vehicle_id' => $vehicleId
            ];
        }

        try {
            $result = self::sendAndReceive($connection, $gpsData, $vehicleId);
            
            // Update usage stats
            self::updateConnectionUsage($poolKey, $connection['id'], $connection['reused']);
            
            // Keep connection for local reuse (don't close it)
            self::storeLocalConnection($poolKey, $connection);
            
            return array_merge($result, [
                'reused' => $connection['reused'],
                'connection_id' => $connection['id']
            ]);
            
        } catch (\Exception $e) {
            // Connection failed, clean it up
            self::cleanupConnection($poolKey, $connection);
            
            self::updateStats($poolKey, 'send_failed');
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'vehicle_id' => $vehicleId,
                'connection_id' => $connection['id'] ?? 'unknown'
            ];
        }
    }

    private static function getLocalConnection(string $poolKey): ?array
    {
        if (!isset(self::$localConnections[$poolKey])) {
            return null;
        }

        $connections = self::$localConnections[$poolKey];
        
        foreach ($connections as $connId => $connection) {
            // Check if connection is still valid and not overused
            if (self::isConnectionValid($connection) && 
                $connection['use_count'] < self::$config['max_reuse_count']) {
                
                $connection['reused'] = true;
                $connection['use_count']++;
                $connection['last_used'] = time();
                
                // Update the local storage
                self::$localConnections[$poolKey][$connId] = $connection;
                
                Log::channel('gps_pool')->debug("Reused LOCAL connection {$connId}", [
                    'process_id' => self::$processId,
                    'use_count' => $connection['use_count']
                ]);
                
                return $connection;
            } else {
                // Connection is invalid or overused, remove it
                if (is_resource($connection['socket'])) {
                    socket_close($connection['socket']);
                }
                unset(self::$localConnections[$poolKey][$connId]);
            }
        }

        return null;
    }

    private static function claimSharedConnection(string $poolKey): ?array
    {
        $sharedConnections = self::getSharedConnections($poolKey);
        
        foreach ($sharedConnections as $connId => $connData) {
            // Skip if in use or too old
            if ($connData['in_use'] || 
                (time() - $connData['last_used']) > self::$config['idle_timeout']) {
                continue;
            }

            // Try to atomically claim this connection
            if (self::atomicClaimConnection($poolKey, $connId)) {
                // Create new socket (since we can't share socket resources between processes)
                $socket = self::createNewSocket($connData['host'], $connData['port']);
                
                if ($socket && self::validateConnection($socket, $connData['host'], $connData['port'])) {
                    $connection = [
                        'id' => $connId,
                        'socket' => $socket,
                        'host' => $connData['host'],
                        'port' => $connData['port'],
                        'created_at' => $connData['created_at'],
                        'last_used' => time(),
                        'use_count' => $connData['use_count'] + 1,
                        'reused' => true // This counts as reuse since we're using existing pool slot
                    ];
                    
                    Log::channel('gps_pool')->debug("Claimed SHARED connection {$connId}", [
                        'process_id' => self::$processId
                    ]);
                    
                    return $connection;
                } else {
                    // Failed to recreate socket, remove from shared pool
                    self::removeSharedConnection($poolKey, $connId);
                    if ($socket) {
                        socket_close($socket);
                    }
                }
            }
        }

        return null;
    }


    private static function createNewConnection(string $host, int $port, string $poolKey): ?array
    {
        $socket = self::createNewSocket($host, $port);
        
        if (!$socket) {
            return null;
        }

        $connId = uniqid('conn_', true);
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

        // Store in shared cache for other processes
        self::storeSharedConnection($poolKey, $connId, [
            'created_at' => $connection['created_at'],
            'host' => $host,
            'port' => $port,
            'process_id' => self::$processId,
            'last_used' => time(),
            'use_count' => 1,
            'in_use' => false
        ]);

        self::updateStats($poolKey, 'created');
        
        Log::channel('gps_pool')->info("Created NEW connection {$connId} for {$poolKey}", [
            'process_id' => self::$processId
        ]);

        return $connection;
    }

    /**
     * Store connection locally for reuse within this process
     */
    private static function storeLocalConnection(string $poolKey, array $connection): void
    {
        if (!isset(self::$localConnections[$poolKey])) {
            self::$localConnections[$poolKey] = [];
        }

        // Limit local pool size
        if (count(self::$localConnections[$poolKey]) >= self::$config['max_connections_per_pool']) {
            // Close oldest connection
            $oldest = array_key_first(self::$localConnections[$poolKey]);
            $oldConnection = self::$localConnections[$poolKey][$oldest];
            if (is_resource($oldConnection['socket'])) {
                socket_close($oldConnection['socket']);
            }
            unset(self::$localConnections[$poolKey][$oldest]);
        }

        self::$localConnections[$poolKey][$connection['id']] = $connection;
    }

    /**
     * Check if a connection is still valid
     */
    private static function isConnectionValid(array $connection): bool
    {
        if (!is_resource($connection['socket'])) {
            return false;
        }

        // Check if socket is still connected
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
     * Atomic operation to claim a connection
     */
    private static function atomicClaimConnection(string $poolKey, string $connId): bool
    {
        $lockKey = "lock_conn_{$poolKey}_{$connId}";
        
        // Try to acquire lock for 1 second
        if (Cache::add($lockKey, self::$processId, 1)) {
            $connections = self::getSharedConnections($poolKey);
            
            if (isset($connections[$connId]) && !$connections[$connId]['in_use']) {
                $connections[$connId]['in_use'] = true;
                $connections[$connId]['claimed_by'] = self::$processId;
                $connections[$connId]['claimed_at'] = time();
                
                Cache::put("shared_connections_{$poolKey}", $connections, self::$config['connection_timeout']);
                Cache::forget($lockKey);
                return true;
            }
            
            Cache::forget($lockKey);
        }
        
        return false;
    }

    /**
     * Update connection usage statistics
     */
    private static function updateConnectionUsage(string $poolKey, string $connId, bool $reused): void
    {
        // Update shared cache
        $connections = self::getSharedConnections($poolKey);
        if (isset($connections[$connId])) {
            $connections[$connId]['use_count']++;
            $connections[$connId]['last_used'] = time();
            $connections[$connId]['in_use'] = false; // Release it
            unset($connections[$connId]['claimed_by'], $connections[$connId]['claimed_at']);
            
            Cache::put("shared_connections_{$poolKey}", $connections, self::$config['connection_timeout']);
        }

        // Update stats
        self::updateStats($poolKey, 'success', $reused);
    }

    /**
     * Clean up failed connection
     */
    private static function cleanupConnection(string $poolKey, array $connection): void
    {
        // Remove from local pool
        if (isset(self::$localConnections[$poolKey][$connection['id']])) {
            unset(self::$localConnections[$poolKey][$connection['id']]);
        }

        // Remove from shared pool
        self::removeSharedConnection($poolKey, $connection['id']);

        // Close socket
        if (is_resource($connection['socket'])) {
            socket_close($connection['socket']);
        }
    }

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
                'use_count' => 0,
                'in_use' => false
            ]);
            
            // Limit pool size
            if (count($connections) > self::$config['max_connections_per_pool']) {
                $oldest = array_key_first($connections);
                unset($connections[$oldest]);
            }
            
            Cache::put("shared_connections_{$poolKey}", $connections, self::$config['connection_timeout']);
        } catch (\Exception $e) {
            // Ignore cache errors
        }
    }

    /**
     * Claim a connection (atomic operation)
     */
    private static function claimConnection(string $poolKey, string $connId): bool
    {
        try {
            $connections = self::getSharedConnections($poolKey);
            
            if (isset($connections[$connId]) && !$connections[$connId]['in_use']) {
                $connections[$connId]['in_use'] = true;
                $connections[$connId]['claimed_by'] = self::$processId;
                $connections[$connId]['claimed_at'] = time();
                
                Cache::put("shared_connections_{$poolKey}", $connections, self::$config['connection_timeout']);
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Release connection back to pool
     */
    private static function releaseConnection(string $poolKey, string $connId): void
    {
        try {
            $connections = self::getSharedConnections($poolKey);
            
            if (isset($connections[$connId])) {
                $connections[$connId]['in_use'] = false;
                $connections[$connId]['last_used'] = time();
                unset($connections[$connId]['claimed_by'], $connections[$connId]['claimed_at']);
                
                Cache::put("shared_connections_{$poolKey}", $connections, self::$config['connection_timeout']);
            }
        } catch (\Exception $e) {
            // Ignore cache errors
        }
    }

    /**
     * Update connection usage stats
     */
    private static function updateSharedConnectionUsage(string $poolKey, string $connId): void
    {
        try {
            $connections = self::getSharedConnections($poolKey);
            
            if (isset($connections[$connId])) {
                $connections[$connId]['use_count']++;
                $connections[$connId]['last_used'] = time();
                
                Cache::put("shared_connections_{$poolKey}", $connections, self::$config['connection_timeout']);
            }
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

        // Optimized socket settings
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
     * Recreate socket from connection data
     */
    private static function recreateSocket(array $connData)
    {
        // For simplicity, we create a new socket each time
        // In a real implementation, you might use socket passing or other techniques
        return self::createNewSocket($connData['host'], $connData['port']);
    }

    /**
     * Validate that connection is still working
     */
    private static function validateConnection($socket, string $host, int $port): bool
    {
        if (!is_resource($socket)) {
            return false;
        }
        
        $error = socket_get_option($socket, SOL_SOCKET, SO_ERROR);
        return $error === 0;
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
     * Update statistics in database
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

    /**
     * Get statistics
     */
    public static function getStats(): array
    {
        try {
            // Get all shared connections info
            $allPools = [];
            $poolKeys = ['10.21.14.8:1401', '10.21.14.8:1403']; // Add your known pools
            
            foreach ($poolKeys as $poolKey) {
                $connections = self::getSharedConnections($poolKey);
                $allPools[$poolKey] = [
                    'total_shared' => count($connections),
                    'available' => count(array_filter($connections, fn($c) => !$c['in_use'])),
                    'in_use' => count(array_filter($connections, fn($c) => $c['in_use'])),
                    'connections' => $connections
                ];
            }
            
            $processStats = ConnectionPoolStat::where('process_id', self::$processId)->get()
                ->keyBy('pool_key')
                ->map(function ($stat) {
                    return [
                        'created' => $stat->created,
                        'success' => $stat->success,
                        'reused' => $stat->reused,
                        'send_failed' => $stat->send_failed,
                        'connection_failed' => $stat->connection_failed,
                        'reuse_ratio' => $stat->getReuseRatio(),
                        'success_rate' => $stat->getSuccessRate()
                    ];
                })
                ->toArray();
            
            $globalStats = ConnectionPoolStat::getAllPoolsStats();
            
        } catch (\Exception $e) {
            $allPools = [];
            $processStats = [];
            $globalStats = [];
        }

        return [
            'process_id' => self::$processId,
            'shared_pools' => $allPools,
            'process_stats' => $processStats,
            'global_stats' => $globalStats,
            'pool_type' => 'persistent_shared_cache'
        ];
    }
}