<?php

declare(strict_types=1);

namespace App\Services\SocketPool\Client;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\Services\SocketPool\Exceptions\SocketPoolException;
use App\Services\SocketPool\Exceptions\ConnectionException;

/**
 * Socket Pool Client for Laravel Management Portal
 * Communicates with the Socket Pool Service via Unix domain socket
 */
class SocketPoolClient
{
    private string $socketPath;
    private int $timeout;
    private array $config = [];
    private static ?SocketPoolClient $instance = null;

    public function __construct(string $socketPath = '/tmp/socket_pool_service.sock', int $timeout = 5)
    {
        $this->socketPath = $socketPath;
        $this->timeout = $timeout;
        $this->loadConfiguration();
    }

    public static function getInstance(): SocketPoolClient
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function loadConfiguration(): void
    {
      $this->config = [
            'cache_enabled' => config('socket_pool.cache_enabled', true),
            'cache_ttl' => (int) config('socket_pool.cache_ttl', 300),
            'retry_attempts' => (int) config('socket_pool.retry_attempts', 3),
            'retry_delay' => (int) config('socket_pool.retry_delay', 100),
            'circuit_breaker_enabled' => config('socket_pool.circuit_breaker_enabled', true),
            'circuit_breaker_threshold' => (int) config('socket_pool.circuit_breaker_threshold', 5),
            'circuit_breaker_timeout' => (int) config('socket_pool.circuit_breaker_timeout', 60),
            'metrics_enabled' => config('socket_pool.metrics_enabled', true),
            'socket_path' => config('socket_pool.socket_path', '/tmp/socket_pool_service.sock'),
            'timeout' => (int) config('socket_pool.timeout', 5),
        ];
        
        $this->socketPath = $this->config['socket_path'];
        $this->timeout = $this->config['timeout'];
    }

    /**
     * Send GPS data using the socket pool service
     */
    public function sendGpsData(string $gpsData, string $host, int $port, string $vehicleId, array $options = []): array
    {
        $requestId = (string) Str::uuid();
        $startTime = microtime(true);
        
        Log::debug("Sending GPS data via Socket Pool", [
            'request_id' => $requestId,
            'vehicle_id' => $vehicleId,
            'host' => $host,
            'port' => $port
        ]);

        try {
            // Check circuit breaker
            if ($this->isCircuitBreakerOpen($host, $port)) {
                throw new SocketPoolException("Circuit breaker is open for $host:$port");
            }

            // Check cache for recent similar requests (optional optimization)
            $cacheKey = $this->getCacheKey('gps_send', $host, $port, $gpsData);
            if ($this->config['cache_enabled'] && isset($options['use_cache']) && $options['use_cache']) {
                $cachedResult = Cache::get($cacheKey);
                if ($cachedResult) {
                    Log::debug("Returning cached GPS result", ['request_id' => $requestId]);
                    return $cachedResult;
                }
            }

            $request = [
                'action' => 'send_gps',
                'message' => $gpsData,
                'host' => $host,
                'port' => $port,
                'vehicle_id' => $vehicleId,
                'request_id' => $requestId,
                'options' => $options
            ];

            $result = $this->sendRequestWithRetry($request);
            
            // Cache successful results if enabled
            if ($result['success'] && $this->config['cache_enabled'] && isset($options['use_cache']) && $options['use_cache']) {
                Cache::put($cacheKey, $result, $this->config['cache_ttl']);
            }

            // Record metrics
            $this->recordMetric('gps_send', [
                'success' => $result['success'],
                'host' => $host,
                'port' => $port,
                'duration' => (microtime(true) - $startTime) * 1000,
                'vehicle_id' => $vehicleId
            ]);

            // Update circuit breaker
            $this->updateCircuitBreaker($host, $port, $result['success']);

            return $result;

        } catch (\Exception $e) {
            Log::error("Error sending GPS data via Socket Pool", [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'host' => $host,
                'port' => $port
            ]);

            // Update circuit breaker on failure
            $this->updateCircuitBreaker($host, $port, false);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'request_id' => $requestId,
                'duration' => (microtime(true) - $startTime) * 1000
            ];
        }
    }

    /**
     * Batch send GPS data for multiple vehicles
     */
    public function batchSendGpsData(array $gpsDataArray, array $options = []): array
    {
        $batchId = Str::uuid();
        $startTime = microtime(true);
        $results = [];
        
        Log::info("Starting batch GPS send via Socket Pool", [
            'batch_id' => $batchId,
            'count' => count($gpsDataArray)
        ]);

        foreach ($gpsDataArray as $index => $data) {
            $result = $this->sendGpsData(
                $data['gps_data'] ?? '',
                $data['host'] ?? '',
                $data['port'] ?? 0,
                $data['vehicle_id'] ?? '',
                $data['options'] ?? []
            );
            
            $result['batch_id'] = $batchId;
            $result['batch_index'] = $index;
            $results[] = $result;
        }

        $duration = (microtime(true) - $startTime) * 1000;
        $successful = count(array_filter($results, fn($r) => $r['success']));
        $failed = count($results) - $successful;

        Log::info("Batch GPS send completed", [
            'batch_id' => $batchId,
            'total' => count($results),
            'successful' => $successful,
            'failed' => $failed,
            'duration' => $duration
        ]);

        return [
            'batch_id' => $batchId,
            'results' => $results,
            'summary' => [
                'total' => count($results),
                'successful' => $successful,
                'failed' => $failed,
                'duration' => $duration
            ]
        ];
    }

    /**
     * Get connection statistics from the socket pool service
     */
    public function getConnectionStats(): array
    {
        $request = ['action' => 'get_stats'];
        return $this->sendRequest($request);
    }

    /**
     * Get service metrics
     */
    public function getMetrics(): array
    {
        $request = ['action' => 'get_metrics'];
        return $this->sendRequest($request);
    }

    /**
     * Close a specific connection in the pool
     */
    public function closeConnection(string $host, int $port): array
    {
        $request = [
            'action' => 'close_connection',
            'host' => $host,
            'port' => $port
        ];
        return $this->sendRequest($request);
    }

    /**
     * Perform health check on the service
     */
    public function performHealthCheck(): array
    {
        $request = ['action' => 'health_check'];
        return $this->sendRequest($request);
    }

    /**
     * Get service configuration
     */
    public function getConfiguration(): array
    {
        $request = ['action' => 'get_config'];
        return $this->sendRequest($request);
    }

    /**
     * Send request with retry logic
     */
    private function sendRequestWithRetry(array $request): array
    {
        $lastException = null;
        
        for ($attempt = 1; $attempt <= $this->config['retry_attempts']; $attempt++) {
            try {
                $result = $this->sendRequest($request);
                
                if ($result['success']) {
                    return $result;
                }
                
                // If not successful but no exception, treat as retriable
                if ($attempt < $this->config['retry_attempts']) {
                    Log::warning("Socket Pool request failed, retrying", [
                        'attempt' => $attempt,
                        'error' => $result['error'] ?? 'Unknown error'
                    ]);
                    usleep($this->config['retry_delay'] * 1000 * $attempt); // Exponential backoff
                    continue;
                }
                
                return $result;
                
            } catch (\Exception $e) {
                $lastException = $e;
                
                if ($attempt < $this->config['retry_attempts']) {
                    Log::warning("Socket Pool request exception, retrying", [
                        'attempt' => $attempt,
                        'error' => $e->getMessage()
                    ]);
                    usleep($this->config['retry_delay'] * 1000 * $attempt);
                } else {
                    throw $e;
                }
            }
        }
        
        if ($lastException) {
            throw $lastException;
        }
        
        return ['success' => false, 'error' => 'Max retry attempts exceeded'];
    }

    /**
     * Send request to the socket pool service
     */
    private function sendRequest(array $request): array
    {
        $socket = null;

        try {
            // Check if socket file exists and is readable
            if (!file_exists($this->socketPath)) {
                Log::error("Socket file not found at path: {$this->socketPath}");
            } elseif (!is_readable($this->socketPath)) {
                Log::error("Socket file is not readable at path: {$this->socketPath}");
            } else {
                Log::debug("Socket file found and readable at path: {$this->socketPath}");
            }

            // Create Unix domain socket
            $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
            if (!$socket) {
                throw new SocketPoolException("Failed to create socket: " . socket_strerror(socket_last_error()));
            }

            // Set timeout
            socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ["sec" => $this->timeout, "usec" => 0]);
            socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ["sec" => $this->timeout, "usec" => 0]);

            // Connect to service
            if (!socket_connect($socket, $this->socketPath)) {
                throw new ConnectionException("Failed to connect to socket service: " . socket_strerror(socket_last_error()));
            }

            // Send request
            $requestJson = json_encode($request);
            $bytesWritten = socket_write($socket, $requestJson, strlen($requestJson));

            if ($bytesWritten === false) {
                throw new ConnectionException("Failed to write to socket: " . socket_strerror(socket_last_error()));
            }

            // Read response
            $response = socket_read($socket, 8192);
            if ($response === false) {
                throw new ConnectionException("Failed to read from socket: " . socket_strerror(socket_last_error()));
            }

            $decodedResponse = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new SocketPoolException("Invalid JSON response: " . json_last_error_msg());
            }

            return $decodedResponse;

        } catch (\Exception $e) {
            Log::error("Socket Pool Client Error: " . $e->getMessage());
            throw $e;
        } finally {
            if ($socket && is_resource($socket)) {
                socket_close($socket);
            }
        }
    }


    /**
     * Check if the socket pool service is running
     */
    public function isServiceRunning(): bool
    {
        if (!file_exists($this->socketPath)) {
            return false;
        }

        try {
            $health = $this->performHealthCheck();
            return $health['success'] ?? false;
        } catch (\Exception $e) {
            Log::debug("Socket Pool service health check failed", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Circuit breaker implementation
     */
    private function isCircuitBreakerOpen(string $host, int $port): bool
    {
        if (!$this->config['circuit_breaker_enabled']) {
            return false;
        }

        $key = $this->getCircuitBreakerKey($host, $port);
        $state = Cache::get($key, []);
        
        if (empty($state)) {
            return false;
        }

        $failures = (int) ($state['failures'] ?? 0);
        $lastFailure = (int) ($state['last_failure'] ?? 0);
        $isOpen = ($state['state'] ?? 'closed') === 'open';

        if ($isOpen) {
            // Check if we should transition to half-open
            if ((time() - $lastFailure) > $this->config['circuit_breaker_timeout']) {
                Cache::put($key, array_merge($state, ['state' => 'half-open']), 3600);
                return false; // Allow one request to test
            }
            return true;
        }

        return $failures >= $this->config['circuit_breaker_threshold'];
    }

    private function updateCircuitBreaker(string $host, int $port, bool $success): void
    {
        if (!$this->config['circuit_breaker_enabled']) {
            return;
        }

        $key = $this->getCircuitBreakerKey($host, $port);
        
        if ($success) {
            // Reset circuit breaker on success
            Cache::forget($key);
        } else {
            // Increment failure count
            $state = Cache::get($key, ['failures' => 0]);
            $state['failures'] = ($state['failures'] ?? 0) + 1;
            $state['last_failure'] = time();
            
            if ($state['failures'] >= $this->config['circuit_breaker_threshold']) {
                $state['state'] = 'open';
            }
            
            Cache::put($key, $state, 3600); // Expire after 1 hour
        }
    }

    private function getCircuitBreakerKey(string $host, int $port): string
    {
        return "socket_pool_circuit_breaker:{$host}:{$port}";
    }

    /**
     * Caching methods
     */
    private function getCacheKey(string $action, string $host, int $port, string $data = ''): string
    {
        return "socket_pool_cache:{$action}:{$host}:{$port}:" . md5($data);
    }

    /**
     * Metrics recording
     */
    private function recordMetric(string $action, array $data): void
    {
        if (!$this->config['metrics_enabled']) {
            return;
        }

        $metric = [
            'action' => $action,
            'timestamp' => time(),
            'data' => $data,
            'client_id' => gethostname()
        ];

        try {
            // Store in Redis for metrics collection
            Redis::lpush('socket_pool_client_metrics', json_encode($metric));
            Redis::ltrim('socket_pool_client_metrics', 0, 999); // Keep last 1000 metrics
        } catch (\Exception $e) {
            Log::warning("Socket Pool metrics recording failed", ['error' => $e->getMessage()]);
        }

        Log::debug("Socket Pool metric recorded", $metric);
    }

    /**
     * Connection testing
     */
    public function testConnection(string $host, int $port): array
    {
        $startTime = microtime(true);
        
        try {
            $result = $this->sendGpsData('PING', $host, $port, 'TEST_CONNECTION', ['test_mode' => true]);
            $duration = (microtime(true) - $startTime) * 1000;
            
            return [
                'success' => $result['success'],
                'host' => $host,
                'port' => $port,
                'response_time' => round($duration, 2),
                'response' => $result['response'] ?? null,
                'error' => $result['error'] ?? null
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'host' => $host,
                'port' => $port,
                'response_time' => round((microtime(true) - $startTime) * 1000, 2),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get service version and info
     */
    public function getServiceInfo(): array
    {
        try {
            $config = $this->getConfiguration();
            $health = $this->performHealthCheck();
            $stats = $this->getConnectionStats();
            
            return [
                'success' => true,
                'service_name' => 'Socket Pool Service',
                'version' => '1.0.0',
                'healthy' => $health['success'] ?? false,
                'pool_size' => $stats['data']['pool_size'] ?? 0,
                'instance_id' => $config['data']['instance_id'] ?? 'unknown',
                'uptime' => $config['data']['uptime'] ?? 0
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}