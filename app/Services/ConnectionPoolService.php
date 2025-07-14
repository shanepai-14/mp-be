<?php

namespace App\Services;

use App\Models\ConnectionPoolStat;
use Illuminate\Support\Facades\Log;

class ConnectionPoolService
{
    private static array $localConnections = [];
    private static bool $initialized = false;
    private static array $config = [];
    private static string $processId = '';
    private static int $lastStatsLog = 0;

    /**
     * Initialize the connection pool service
     */
    public static function init(array $config = []): void
    {
        if (self::$initialized) {
            return;
        }

        self::$config = array_merge([
            'max_connections_per_pool' => 3,
            'connection_timeout' => 300,
            'idle_timeout' => 120,
            'connect_timeout' => 5,
            'socket_timeout' => 3
        ], $config);

        self::$processId = (string) getmypid();
        self::$initialized = true;

        // Log initialization only once per minute per process
        self::logProcessInit();
    }

    /**
     * Log process initialization (throttled)
     */
    private static function logProcessInit(): void
    {
        $now = time();
        if (($now - self::$lastStatsLog) < 60) {
            return; // Skip if logged recently
        }
        
        self::$lastStatsLog = $now;
        
        Log::channel('gps_pool')->info('Eloquent connection pool service initialized', [
            'process_id' => self::$processId,
            'config' => self::$config
        ]);
    }

    /**
     * Send GPS data through pooled connection
     */
    public static function sendGPSData(string $host, int $port, string $gpsData, string $vehicleId): array
    {
        if (!self::$initialized) {
            self::init();
        }

        $poolKey = "{$host}:{$port}";
        
        // Try to reuse existing local connection
        $connection = self::getLocalConnection($poolKey);
        
        if (!$connection) {
            // Create new connection
            $connection = self::createConnection($host, $port, $poolKey);
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
            $result = self::writeAndRead($connection, $gpsData, $vehicleId);
            self::updateStats($poolKey, 'success', $result['reused'] ?? false);
            return $result;
            
        } catch (\Exception $e) {
            // Remove failed connection
            unset(self::$localConnections[$poolKey]);
            self::updateStats($poolKey, 'send_failed');
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'vehicle_id' => $vehicleId
            ];
        }
    }

    /**
     * Get local connection if available and alive
     */
    private static function getLocalConnection(string $poolKey): ?array
    {
        if (!isset(self::$localConnections[$poolKey])) {
            return null;
        }

        $connection = self::$localConnections[$poolKey];
        
        if (self::isConnectionAlive($connection)) {
            $connection['last_used'] = time();
            $connection['use_count']++;
            self::$localConnections[$poolKey] = $connection;
            
            // Occasionally log reuse
            if (mt_rand(1, 100) === 1) {
                Log::channel('gps_pool')->debug("Reusing connection for {$poolKey}", [
                    'connection_id' => $connection['id'],
                    'process_id' => self::$processId,
                    'use_count' => $connection['use_count']
                ]);
            }
            
            return $connection;
        }
        
        // Connection is dead, remove it
        unset(self::$localConnections[$poolKey]);
        return null;
    }

    /**
     * Create new connection
     */
    private static function createConnection(string $host, int $port, string $poolKey): ?array
    {
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
            'use_count' => 1,
            'process_id' => self::$processId
        ];
        
        // Store locally for reuse
        self::$localConnections[$poolKey] = $connection;
        
        // Update stats
        self::updateStats($poolKey, 'created');
        
        // Reduced logging
        if (mt_rand(1, 20) === 1) {
            Log::channel('gps_pool')->info("Created new connection for {$poolKey}", [
                'connection_id' => $connection['id'],
                'process_id' => self::$processId
            ]);
        }
        
        return $connection;
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

        // Optimized socket options
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
     * Write GPS data and read response
     */
    private static function writeAndRead(array $connection, string $gpsData, string $vehicleId): array
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

        // Quick non-blocking read attempt
        socket_set_nonblock($socket);
        $response = '';
        $startTime = microtime(true);
        
        while ((microtime(true) - $startTime) < 0.3) {
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
            
            usleep(2000); // 2ms
        }
        
        socket_set_block($socket);

        return [
            'success' => true,
            'response' => trim($response),
            'bytes_written' => $bytesWritten,
            'vehicle_id' => $vehicleId,
            'connection_id' => $connection['id'],
            'reused' => $connection['use_count'] > 1
        ];
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
     * Update stats using Eloquent model
     */
    private static function updateStats(string $poolKey, string $action, bool $reused = false): void
    {
        try {
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
            
            // Increment the appropriate counter
            $stat->incrementStat($action);
            
            // Also increment reused counter if applicable
            if ($reused) {
                $stat->incrementStat('reused');
            }
            
        } catch (\Exception $e) {
            // Ignore stats errors - don't break GPS functionality
            Log::channel('gps_pool')->debug("Stats update failed: " . $e->getMessage());
        }
    }

    /**
     * Get comprehensive stats using Eloquent
     */
    public static function getStats(): array
    {
        $localStats = [];
        foreach (self::$localConnections as $poolKey => $connection) {
            $localStats[$poolKey] = [
                'connection_id' => $connection['id'],
                'created_at' => $connection['created_at'],
                'last_used' => $connection['last_used'],
                'use_count' => $connection['use_count'],
                'age_seconds' => time() - $connection['created_at']
            ];
        }
        
        try {
            // Get stats for this process
            $processStats = ConnectionPoolStat::where('process_id', self::$processId)->get()
                ->keyBy('pool_key')
                ->map(function ($stat) {
                    return [
                        'created' => $stat->created,
                        'success' => $stat->success,
                        'reused' => $stat->reused,
                        'send_failed' => $stat->send_failed,
                        'connection_failed' => $stat->connection_failed,
                        'last_action' => $stat->last_action,
                        'reuse_ratio' => $stat->getReuseRatio(),
                        'success_rate' => $stat->getSuccessRate(),
                        'is_active' => $stat->isActiveProcess()
                    ];
                })
                ->toArray();
            
            // Get global stats for all pools
            $globalStats = ConnectionPoolStat::getAllPoolsStats();
            
        } catch (\Exception $e) {
            $processStats = [];
            $globalStats = [];
        }

        return [
            'process_id' => self::$processId,
            'local_connections' => count(self::$localConnections),
            'local_details' => $localStats,
            'process_stats' => $processStats,
            'global_stats' => $globalStats,
            'pool_type' => 'eloquent_mongodb'
        ];
    }

    /**
     * Cleanup old connections
     */
    public static function cleanup(): array
    {
        $removed = 0;
        
        foreach (self::$localConnections as $poolKey => $connection) {
            if (!self::isConnectionAlive($connection)) {
                if (is_resource($connection['socket'])) {
                    socket_close($connection['socket']);
                }
                unset(self::$localConnections[$poolKey]);
                $removed++;
            }
        }

        return [
            'process_id' => self::$processId,
            'connections_removed' => $removed,
            'remaining_connections' => count(self::$localConnections)
        ];
    }

    /**
     * Shutdown all connections
     */
    public static function shutdown(): void
    {
        foreach (self::$localConnections as $connection) {
            if (isset($connection['socket']) && is_resource($connection['socket'])) {
                socket_close($connection['socket']);
            }
        }
        
        self::$localConnections = [];
    }

    /**
     * Get analytics data for monitoring
     */
    public static function getAnalytics(): array
    {
        try {
            $allStats = ConnectionPoolStat::getAllPoolsStats();
            $activeProcesses = ConnectionPoolStat::where('updated_at', '>=', now()->subMinutes(5))->count();
            
            $totalSuccess = array_sum(array_column($allStats, 'total_success'));
            $totalCreated = array_sum(array_column($allStats, 'total_created'));
            $totalFailed = array_sum(array_column($allStats, 'total_send_failed')) + 
                          array_sum(array_column($allStats, 'total_connection_failed'));
            
            return [
                'overall_reuse_ratio' => $totalCreated > 0 ? round(array_sum(array_column($allStats, 'total_reused')) / $totalCreated, 2) : 0,
                'overall_success_rate' => ($totalSuccess + $totalFailed) > 0 ? round(($totalSuccess / ($totalSuccess + $totalFailed)) * 100, 2) : 0,
                'active_processes' => $activeProcesses,
                'total_pools' => count($allStats),
                'total_requests_processed' => $totalSuccess + $totalFailed,
                'performance_grade' => self::calculatePerformanceGrade($allStats)
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Calculate performance grade based on metrics
     */
    private static function calculatePerformanceGrade(array $stats): string
    {
        if (empty($stats)) {
            return 'N/A';
        }
        
        $avgReuseRatio = array_sum(array_column($stats, 'reuse_ratio')) / count($stats);
        
        if ($avgReuseRatio >= 5) return 'A+';
        if ($avgReuseRatio >= 3) return 'A';
        if ($avgReuseRatio >= 2) return 'B';
        if ($avgReuseRatio >= 1) return 'C';
        return 'D';
    }
}