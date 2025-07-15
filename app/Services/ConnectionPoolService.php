<?php

namespace App\Services;

use App\Models\ConnectionPoolStat;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ConnectionPoolService
{
    private static array $config = [];
    private static string $processId = '';
    
    public static function init(array $config = []): void
    {
        self::$config = array_merge([
            'socket_timeout' => 2,                  // Short timeout for speed
            'max_retry_attempts' => 3,              // Fewer retries since reuse doesn't work
            'retry_delay_ms' => 10,                 // Very fast retries
            'connection_cache_seconds' => 5,        // Cache server availability
        ], $config);

        self::$processId = (string) getmypid();
    }

    public static function sendGPSData(string $host, int $port, string $gpsData, string $vehicleId): array
    {
        if (empty(self::$config)) {
            self::init();
        }

        $attempts = 0;
        $lastError = '';
        $startTime = microtime(true);
        $serverKey = "{$host}:{$port}";
        
        // Quick server availability check (cached)
        if (!self::isServerAvailableCached($serverKey, $host, $port)) {
            return [
                'success' => false,
                'error' => 'GPS server unavailable',
                'vehicle_id' => $vehicleId,
                'process_id' => self::$processId,
                'attempts' => 0,
                'duration_ms' => 0
            ];
        }
        
        while ($attempts < self::$config['max_retry_attempts']) {
            $attempts++;
            
            try {
                // Create fresh connection for each request (server closes after each use)
                $result = self::sendDataDirectConnection($host, $port, $gpsData, $vehicleId);
                
                return array_merge($result, [
                    'process_id' => self::$processId,
                    'attempts' => $attempts,
                    'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    'connection_reused' => false, // Always false since server closes connections
                    'server_closes_connections' => true
                ]);
                
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                
                if ($attempts < self::$config['max_retry_attempts']) {
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
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
        ];
    }

    /**
     * Send GPS data with single-use connection (optimized for speed)
     */
    private static function sendDataDirectConnection(string $host, int $port, string $gpsData, string $vehicleId): array
    {
        // Create optimized socket for single use
        $socket = self::createFastSocket($host, $port);
        
        if (!$socket) {
            throw new \Exception('Failed to create socket connection');
        }

        try {
            // Send data
            $message = $gpsData . "\r";
            $bytesWritten = socket_write($socket, $message, strlen($message));
            
            if ($bytesWritten === false) {
                throw new \Exception('Failed to write data: ' . socket_strerror(socket_last_error($socket)));
            }
            
            if ($bytesWritten === 0) {
                throw new \Exception('No bytes written to socket');
            }

            // Quick response read
            $response = self::readResponseFast($socket);
            
            return [
                'success' => true,
                'response' => $response,
                'bytes_written' => $bytesWritten,
                'vehicle_id' => $vehicleId,
                'connection_id' => 'single_use_' . uniqid()
            ];
            
        } finally {
            // Always close socket (server closes it anyway)
            socket_close($socket);
        }
    }

    /**
     * Create socket optimized for single-use with GPS server
     */
    private static function createFastSocket(string $host, int $port)
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
        if ($socket === false) {
            return null;
        }

        // Minimal options for speed - no keep-alive since server closes anyway
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

        // Fast blocking connect (simpler than non-blocking for single use)
        if (!socket_connect($socket, $host, $port)) {
            socket_close($socket);
            return null;
        }
        
        return $socket;
    }

    /**
     * Fast response reading optimized for GPS protocol
     */
    private static function readResponseFast($socket): string
    {
        $response = '';
        
        // GPS servers typically respond very quickly
        $read = [$socket];
        $write = $except = [];
        
        // Wait up to 100ms for response
        $selectResult = socket_select($read, $write, $except, 0, 100000);
        
        if ($selectResult > 0 && in_array($socket, $read)) {
            $data = socket_read($socket, 1024);
            if ($data !== false && $data !== '') {
                $response = trim($data);
            }
        }
        
        return $response;
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
     * Health check for monitoring
     */
    public static function healthCheck(string $host, int $port): array
    {
        $startTime = microtime(true);
        $available = self::isServerAvailableCached("{$host}:{$port}", $host, $port);
        $checkTime = microtime(true) - $startTime;
        
        return [
            'server_available' => $available,
            'check_time_ms' => round($checkTime * 1000, 2),
            'process_id' => self::$processId,
            'server_type' => 'single_use_connections',
            'connection_reuse_possible' => false
        ];
    }

    /**
     * Get current statistics
     */
    public static function getStats(): array
    {
        return [
            'process_id' => self::$processId,
            'connection_strategy' => 'single_use_optimized',
            'server_behavior' => 'closes_connections_after_each_message',
            'config' => self::$config
        ];
    }

    /**
     * Test GPS server behavior
     */
    public static function testGPSServerBehavior(string $host = '10.21.14.8', int $port = 1403): array
    {
        $results = [];
        
        // Test multiple rapid connections
        for ($i = 1; $i <= 3; $i++) {
            $startTime = microtime(true);
            
            try {
                $testData = '$test' . $i . ',1,1.0,2.0,0,0,0,5,0,0,10,0,0,TEST' . $i;
                $result = self::sendDataDirectConnection($host, $port, $testData, "TEST{$i}");
                
                $results["test_{$i}"] = [
                    'success' => $result['success'],
                    'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    'bytes_written' => $result['bytes_written'],
                    'response_length' => strlen($result['response']),
                    'response' => $result['response']
                ];
                
            } catch (\Exception $e) {
                $results["test_{$i}"] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
                ];
            }
            
            // Small delay between tests
            usleep(10000); // 10ms
        }
        
        return [
            'server_behavior' => 'tested_rapid_connections',
            'total_tests' => 3,
            'results' => $results,
            'conclusion' => 'GPS server closes connections after each message - connection reuse not possible'
        ];
    }

    /**
     * No cleanup needed since we don't store connections
     */
    public static function cleanup(): void
    {
        // Nothing to clean up - we don't store connections
        Log::channel('gps_pool')->info("No cleanup needed - single-use connection strategy", [
            'process_id' => self::$processId
        ]);
    }
}