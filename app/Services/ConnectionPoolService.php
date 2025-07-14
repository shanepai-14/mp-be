<?php

namespace App\Services;

use App\Models\ConnectionPoolStat;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class PersistentSharedPoolService
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
        $connectionAttempted = false;
        $connection = null;
        
        // Try to get an existing connection from shared storage
        $existingConnections = self::getSharedConnections($poolKey);
        
        foreach ($existingConnections as $connId => $connData) {
            if (self::claimConnection($poolKey, $connId)) {
                // Successfully claimed a connection, now validate it
                $socket = self::recreateSocket($connData);
                if ($socket && self::validateConnection($socket, $host, $port)) {
                    $connection = [
                        'id' => $connId,
                        'socket' => $socket,
                        'host' => $host,
                        'port' => $port,
                        'created_at' => $connData['created_at'],
                        'reused' => true
                    ];
                    
                    Log::channel('gps_pool')->debug("Reused connection {$connId} for {$poolKey}", [
                        'process_id' => self::$processId,
                        'vehicle' => $vehicleId
                    ]);
                    break;
                } else {
                    // Connection is dead, remove it
                    self::removeSharedConnection($poolKey, $connId);
                    if ($socket) {
                        socket_close($socket);
                    }
                }
            }
        }
        
        // If no reusable connection found, create new one
        if (!$connection) {
            $socket = self::createNewSocket($host, $port);
            if ($socket) {
                $connId = uniqid();
                $connection = [
                    'id' => $connId,
                    'socket' => $socket,
                    'host' => $host,
                    'port' => $port,
                    'created_at' => time(),
                    'reused' => false
                ];
                
                // Store in shared storage for other processes to reuse
                self::storeSharedConnection($poolKey, $connId, [
                    'created_at' => $connection['created_at'],
                    'host' => $host,
                    'port' => $port,
                    'process_id' => self::$processId
                ]);
                
                self::updateStats($poolKey, 'created');
                
                // Log creation occasionally
                if (mt_rand(1, 20) === 1) {
                    Log::channel('gps_pool')->info("Created new shared connection for {$poolKey}", [
                        'connection_id' => $connId,
                        'process_id' => self::$processId
                    ]);
                }
            }
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
            
            // Update connection usage
            self::updateSharedConnectionUsage($poolKey, $connection['id']);
            
            if ($connection['reused']) {
                self::updateStats($poolKey, 'success', true);
            } else {
                self::updateStats($poolKey, 'success', false);
            }
            
            // Release connection back to shared pool
            self::releaseConnection($poolKey, $connection['id']);
            
            return array_merge($result, [
                'reused' => $connection['reused'],
                'connection_id' => $connection['id']
            ]);
            
        } catch (\Exception $e) {
            // Connection failed, remove from shared storage
            self::removeSharedConnection($poolKey, $connection['id']);
            if (is_resource($connection['socket'])) {
                socket_close($connection['socket']);
            }
            
            self::updateStats($poolKey, 'send_failed');
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'vehicle_id' => $vehicleId
            ];
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