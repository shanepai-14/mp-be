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
            'retry_delay_ms' => 10,  
            'read_timeout_ms' => 100000,               // Very fast retries
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

     public static function sendDataWithPooling(string $host, int $port, array $messages): array
    {
        $socket = self::createFastSocket($host, $port);
        $results = [];
        
        if (!$socket) {
            throw new \Exception('Failed to create socket connection');
        }
        
        try {
            foreach ($messages as $index => $messageData) {
                // Check if socket is still alive before each message
                if (!self::isSocketAlive($socket)) {
                    $results[$index] = [
                        'success' => false,
                        'error' => 'Socket connection lost',
                        'vehicle_id' => $messageData['vehicle_id'] ?? null
                    ];
                    break;
                }
                
                try {
                    $result = self::sendDataToSocket(
                        $socket, 
                        $messageData['gps_data'], 
                        $messageData['vehicle_id']
                    );
                    $results[$index] = $result;
                    
                    // If socket died after this message, stop processing
                    if (!$result['socket_alive_after']) {
                        $results[$index]['note'] = 'Server closed connection after this message';
                        break;
                    }
                    
                } catch (\Exception $e) {
                    $results[$index] = [
                        'success' => false,
                        'error' => $e->getMessage(),
                        'vehicle_id' => $messageData['vehicle_id'] ?? null
                    ];
                    break;
                }
            }
        } finally {
            socket_close($socket);
        }
        
        return [
            'total_messages' => count($messages),
            'processed_messages' => count($results),
            'successful_messages' => count(array_filter($results, fn($r) => $r['success'] ?? false)),
            'results' => $results
        ];
    }

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
                'response' => $result['response'],
                'connection_closed_after_response' => $result['connection_closed'] ?? null
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

    // Auto conclusion based on connection_closed_after_response
    $allClosed = collect($results)->every(fn($r) => $r['success'] && ($r['connection_closed_after_response'] ?? false));
    $conclusion = $allClosed
        ? 'GPS server closes connections after each message - connection reuse not possible'
        : 'GPS server keeps connections open - connection reuse might be possible';

    return [
        'server_behavior' => 'tested_rapid_connections',
        'total_tests' => 3,
        'results' => $results,
        'conclusion' => $conclusion
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

  public static function testConnectionReuse(string $host = '10.21.14.8', int $port = 1403): array
    {
        $results = [];
        $socket = null;
        
        try {
            // Create one socket for all tests
            $socket = self::createFastSocket($host, $port);
            if (!$socket) {
                throw new \Exception('Failed to create initial socket');
            }
            
            $results['socket_created'] = true;
            
            // Test multiple messages on same socket
            for ($i = 1; $i <= 5; $i++) {
                $startTime = microtime(true);
                
                try {
                    $testData = '$test' . $i . ',1,1.0,2.0,0,0,0,5,0,0,10,0,0,TEST' . $i;
                    
                    // Check if socket is still alive before sending
                    $isAlive = self::isSocketAlive($socket);
                    
                    if (!$isAlive) {
                        $results["test_{$i}"] = [
                            'success' => false,
                            'error' => 'Socket died before test',
                            'socket_alive_before' => false
                        ];
                        break;
                    }
                    
                    // Send message
                    $message = $testData . "\r";
                    $bytesWritten = socket_write($socket, $message, strlen($message));
                    
                    if ($bytesWritten === false) {
                        throw new \Exception('Write failed: ' . socket_strerror(socket_last_error($socket)));
                    }
                    
                    // Read response
                    $response = self::readResponseFast($socket);
                    
                    // Check if socket is still alive after response
                    $isAliveAfter = self::isSocketAlive($socket);
                    
                    $results["test_{$i}"] = [
                        'success' => true,
                        'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                        'bytes_written' => $bytesWritten,
                        'response' => $response,
                        'socket_alive_before' => true,
                        'socket_alive_after' => $isAliveAfter
                    ];
                    
                    // If socket died, no point continuing
                    if (!$isAliveAfter) {
                        $results["test_{$i}"]['note'] = 'Server closed connection after this message';
                        break;
                    }
                    
                    // Small delay between messages
                    usleep(10000); // 10ms
                    
                } catch (\Exception $e) {
                    $results["test_{$i}"] = [
                        'success' => false,
                        'error' => $e->getMessage(),
                        'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                        'socket_alive_before' => $isAlive ?? false
                    ];
                    break;
                }
            }
            
        } finally {
            if ($socket) {
                socket_close($socket);
            }
        }
        
        return self::analyzeReuseResults($results);
    }
    
    /**
     * Compare single connection vs multiple connections performance
     */
    public static function testConnectionPoolingBenefit(string $host = '10.21.14.8', int $port = 1403): array
    {
        // Prepare test messages
        $messages = [
            ['gps_data' => '$test1,1,1.0,2.0,0,0,0,5,0,0,10,0,0,TEST1', 'vehicle_id' => 'TEST1'],
            ['gps_data' => '$test2,1,1.0,2.0,0,0,0,5,0,0,10,0,0,TEST2', 'vehicle_id' => 'TEST2'],
            ['gps_data' => '$test3,1,1.0,2.0,0,0,0,5,0,0,10,0,0,TEST3', 'vehicle_id' => 'TEST3'],
            ['gps_data' => '$test4,1,1.0,2.0,0,0,0,5,0,0,10,0,0,TEST4', 'vehicle_id' => 'TEST4'],
            ['gps_data' => '$test5,1,1.0,2.0,0,0,0,5,0,0,10,0,0,TEST5', 'vehicle_id' => 'TEST5']
        ];

        $messages2 = [
            ['gps_data' => '$test11,1,1.0,2.0,0,0,0,5,0,0,10,0,0,TEST11', 'vehicle_id' => 'TEST11'],
            ['gps_data' => '$test22,1,1.0,2.0,0,0,0,5,0,0,10,0,0,TEST22', 'vehicle_id' => 'TEST22'],
            ['gps_data' => '$test33,1,1.0,2.0,0,0,0,5,0,0,10,0,0,TEST33', 'vehicle_id' => 'TEST33'],
            ['gps_data' => '$test44,1,1.0,2.0,0,0,0,5,0,0,10,0,0,TEST44', 'vehicle_id' => 'TEST44'],
            ['gps_data' => '$test55,1,1.0,2.0,0,0,0,5,0,0,10,0,0,TEST55', 'vehicle_id' => 'TEST55']
        ];
        
        // Test 1: Multiple connections (current approach)
        $multiStart = microtime(true);
        $multiConnResults = [];
        
        foreach ($messages as $index => $messageData) {
            try {
                $result = self::sendDataDirectConnection(
                    $host, 
                    $port, 
                    $messageData['gps_data'], 
                    $messageData['vehicle_id']
                );
                $multiConnResults[$index] = $result;
            } catch (\Exception $e) {
                $multiConnResults[$index] = [
                    'success' => false, 
                    'error' => $e->getMessage(),
                    'vehicle_id' => $messageData['vehicle_id']
                ];
            }
        }
        $multiTotalTime = microtime(true) - $multiStart;
        
        // Test 2: Single connection with pooling
        $singleStart = microtime(true);
        $singleConnResult = [];
        
        try {
            $singleConnResult = self::sendDataWithPooling($host, $port, $messages2);
        } catch (\Exception $e) {
            $singleConnResult = [
                'total_messages' => count($messages),
                'processed_messages' => 0,
                'successful_messages' => 0,
                'results' => [],
                'error' => $e->getMessage()
            ];
        }
        
        $singleTotalTime = microtime(true) - $singleStart;
        
        // Calculate performance metrics
        $multiSuccessful = count(array_filter($multiConnResults, fn($r) => $r['success'] ?? false));
        $singleSuccessful = $singleConnResult['successful_messages'] ?? 0;
        
        $performanceImprovement = 'N/A';
        if ($singleTotalTime > 0 && $multiTotalTime > 0) {
            $improvement = (($multiTotalTime - $singleTotalTime) / $multiTotalTime) * 100;
            $performanceImprovement = round($improvement, 1) . '%';
        }
        
        return [
            'test_config' => [
                'host' => $host,
                'port' => $port,
                'total_messages' => count($messages)
            ],
            'multiple_connections' => [
                'approach' => 'New socket for each message',
                'total_time_ms' => round($multiTotalTime * 1000, 2),
                'successful_messages' => $multiSuccessful,
                'failed_messages' => count($messages) - $multiSuccessful,
                'avg_time_per_message_ms' => round(($multiTotalTime * 1000) / count($messages), 2),
                'results' => $multiConnResults
            ],
            'single_connection' => [
                'approach' => 'Reuse single socket for all messages',
                'total_time_ms' => round($singleTotalTime * 1000, 2),
                'successful_messages' => $singleSuccessful,
                'failed_messages' => count($messages) - $singleSuccessful,
                'processed_messages' => $singleConnResult['processed_messages'] ?? 0,
                'avg_time_per_message_ms' => $singleSuccessful > 0 ? 
                    round(($singleTotalTime * 1000) / $singleSuccessful, 2) : 'N/A',
                'results' => $singleConnResult['results'] ?? [],
                'error' => $singleConnResult['error'] ?? null
            ],
            'performance_comparison' => [
                'time_improvement' => $performanceImprovement,
                'connection_reuse_viable' => $singleSuccessful > 1,
                'recommendation' => self::generatePoolingRecommendation(
                    $multiTotalTime, 
                    $singleTotalTime, 
                    $multiSuccessful, 
                    $singleSuccessful,
                    $singleConnResult['processed_messages'] ?? 0
                )
            ]
        ];
    }

       private static function generatePoolingRecommendation(
        float $multiTime, 
        float $singleTime, 
        int $multiSuccessful, 
        int $singleSuccessful,
        int $singleProcessed
    ): string {
        // If single connection failed to process any messages
        if ($singleSuccessful === 0) {
            return 'Connection pooling not viable - server immediately closes connections';
        }
        
        // If single connection processed only 1 message
        if ($singleProcessed === 1) {
            return 'Connection pooling not beneficial - server closes connection after first message';
        }
        
        // If single connection processed multiple messages successfully
        if ($singleSuccessful > 1) {
            $timeImprovement = (($multiTime - $singleTime) / $multiTime) * 100;
            
            if ($timeImprovement > 20) {
                return 'Connection pooling highly recommended - significant performance improvement (' . round($timeImprovement, 1) . '%)';
            } elseif ($timeImprovement > 5) {
                return 'Connection pooling recommended - moderate performance improvement (' . round($timeImprovement, 1) . '%)';
            } else {
                return 'Connection pooling viable but minimal performance benefit (' . round($timeImprovement, 1) . '%)';
            }
        }
        
        return 'Connection pooling assessment inconclusive - manual testing recommended';
    }
    
    /**
     * Check if socket is still alive and writable
     */
    private static function isSocketAlive($socket): bool
    {
        if (!$socket) return false;
        
        // Use socket_select to check if socket is writable
        $read = $except = [];
        $write = [$socket];
        
        $result = socket_select($read, $write, $except, 0, 1000); // 1ms timeout
        
        if ($result === false) return false;
        if ($result === 0) return true; // Timeout means socket is probably fine
        
        // If socket is in write array, it's writable
        return in_array($socket, $write);
    }
    
    /**
     * Analyze connection reuse test results
     */
    private static function analyzeReuseResults(array $results): array
    {
        $successfulTests = array_filter($results, fn($key) => strpos($key, 'test_') === 0 && $results[$key]['success'] === true, ARRAY_FILTER_USE_KEY);
        $testCount = count($successfulTests);
        
        $canReuse = false;
        $maxReusable = 0;
        
        if ($testCount > 1) {
            // Check how many consecutive successful reuses
            for ($i = 1; $i <= 5; $i++) {
                if (isset($results["test_{$i}"]) && $results["test_{$i}"]['success']) {
                    $maxReusable = $i;
                    if ($i > 1) $canReuse = true;
                } else {
                    break;
                }
            }
        }
        
        return [
            'connection_reuse_possible' => $canReuse,
            'max_reusable_messages' => $maxReusable,
            'total_successful_tests' => $testCount,
            'detailed_results' => $results,
            'recommendation' => $canReuse ? 
                'Connection pooling recommended - can reuse connections' : 
                'Connection pooling not beneficial - server closes after each message'
        ];
    }
    

    

    
 
}