<?php

namespace App\Services;

use App\Models\ConnectionPoolStat;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ConnectionPoolService
{
    private static array $activeConnections = [];
    private static array $config = [];
    private static string $processId = '';
    
    /**
     * Initialize the service (lightweight)
     */
    public static function init(array $config = []): void
    {
        self::$config = array_merge([
            'max_connections_per_pool' => 2,  // Reduced since processes restart
            'connection_timeout' => 180,      // 3 minutes
            'idle_timeout' => 60,             // 1 minute
            'socket_timeout' => 3
        ], $config);

        self::$processId = (string) getmypid();
    }

    /**
     * Send GPS data with smart connection management
     */
    public static function sendGPSData(string $host, int $port, string $gpsData, string $vehicleId): array
    {
        if (empty(self::$config)) {
            self::init();
        }

        $poolKey = "{$host}:{$port}";
        
        // Try to reuse existing connection from this request cycle
        $connection = self::getActiveConnection($poolKey);
        
        if (!$connection || !self::isSocketAlive($connection['socket'])) {
            // Create new connection
            $connection = self::createFreshConnection($host, $port, $poolKey);
        }
        
        if (!$connection) {
            self::updateStats($poolKey, 'connection_failed');
            return [
                'success' => false,
                'error' => 'Could not create connection',
                'vehicle_id' => $vehicleId
            ];
        }

        try {
            $result = self::sendAndReceive($connection, $gpsData, $vehicleId);
            
            // Keep connection alive for this request cycle
            $connection['last_used'] = time();
            $connection['use_count']++;
            self::$activeConnections[$poolKey] = $connection;
            
            $reused = $connection['use_count'] > 1;
            self::updateStats($poolKey, 'success', $reused);
            
            return array_merge($result, [
                'reused' => $reused,
                'connection_id' => $connection['id'],
                'use_count' => $connection['use_count']
            ]);
            
        } catch (\Exception $e) {
            // Remove failed connection
            self::closeConnection($poolKey);
            self::updateStats($poolKey, 'send_failed');
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'vehicle_id' => $vehicleId
            ];
        }
    }

    /**
     * Get active connection for this request
     */
    private static function getActiveConnection(string $poolKey): ?array
    {
        return self::$activeConnections[$poolKey] ?? null;
    }

    /**
     * Create a fresh connection
     */
    private static function createFreshConnection(string $host, int $port, string $poolKey): ?array
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
            $connection = [
                'id' => uniqid(),
                'socket' => $socket,
                'host' => $host,
                'port' => $port,
                'created_at' => time(),
                'last_used' => time(),
                'use_count' => 0
            ];
            
            self::updateStats($poolKey, 'created');
            
            // Only log 1 in 50 creations to reduce noise
            if (mt_rand(1, 50) === 1) {
                Log::channel('gps_pool')->info("Created connection for {$poolKey}", [
                    'connection_id' => $connection['id'],
                    'process_id' => self::$processId
                ]);
            }
            
            return $connection;
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
        
        while ((microtime(true) - $startTime) < 0.2) { // 200ms timeout
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
            
            usleep(1000); // 1ms
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
     * Check if socket is still alive
     */
    private static function isSocketAlive($socket): bool
    {
        if (!is_resource($socket)) {
            return false;
        }
        
        $error = socket_get_option($socket, SOL_SOCKET, SO_ERROR);
        return $error === 0;
    }

    /**
     * Close connection and cleanup
     */
    private static function closeConnection(string $poolKey): void
    {
        if (isset(self::$activeConnections[$poolKey])) {
            $connection = self::$activeConnections[$poolKey];
            if (is_resource($connection['socket'])) {
                socket_close($connection['socket']);
            }
            unset(self::$activeConnections[$poolKey]);
        }
    }

    /**
     * Update statistics in database (optimized)
     */
    private static function updateStats(string $poolKey, string $action, bool $reused = false): void
    {
        try {
            // Use cache to reduce database hits
            $cacheKey = "pool_stat_{$poolKey}_" . self::$processId;
            $statId = Cache::get($cacheKey);
            
            if (!$statId) {
                // Find or create stats record
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
                
                Cache::put($cacheKey, $stat->id, 300); // Cache for 5 minutes
                $statId = $stat->id;
            }
            
            // Batch update to reduce database calls
            ConnectionPoolStat::where('id', $statId)->increment($action);
            
            if ($reused) {
                ConnectionPoolStat::where('id', $statId)->increment('reused');
            }
            
            // Update last action less frequently
            if (mt_rand(1, 10) === 1) {
                ConnectionPoolStat::where('id', $statId)->update([
                    'last_action' => $action,
                    'last_action_time' => now()
                ]);
            }
            
        } catch (\Exception $e) {
            // Ignore stats errors
        }
    }

    /**
     * Get statistics
     */
    public static function getStats(): array
    {
        $localConnections = count(self::$activeConnections);
        $connectionDetails = [];
        
        foreach (self::$activeConnections as $poolKey => $connection) {
            $connectionDetails[$poolKey] = [
                'connection_id' => $connection['id'],
                'use_count' => $connection['use_count'],
                'age_seconds' => time() - $connection['created_at'],
                'last_used_seconds_ago' => time() - $connection['last_used']
            ];
        }

        try {
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
            $processStats = [];
            $globalStats = [];
        }

        return [
            'process_id' => self::$processId,
            'active_connections' => $localConnections,
            'connection_details' => $connectionDetails,
            'process_stats' => $processStats,
            'global_stats' => $globalStats,
            'pool_type' => 'database_backed_per_request'
        ];
    }

    /**
     * Cleanup (called automatically by PHP)
     */
    public static function cleanup(): array
    {
        $closed = 0;
        
        foreach (self::$activeConnections as $poolKey => $connection) {
            if (is_resource($connection['socket'])) {
                socket_close($connection['socket']);
                $closed++;
            }
        }
        
        self::$activeConnections = [];
        
        return [
            'process_id' => self::$processId,
            'connections_closed' => $closed
        ];
    }
}