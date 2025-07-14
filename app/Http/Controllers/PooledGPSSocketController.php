<?php

namespace App\Http\Controllers;

use App\Services\ConnectionPoolManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PooledGPSSocketController extends Controller
{
    public function __construct()
    {
        // Optimized settings for multiple PHP-FPM processes
        ConnectionPoolManager::init([
            'max_connections_per_pool' => 5,   // Reduced per process (5 × 14 processes = 70 total)
            'connection_timeout' => 600,       // 10 minutes (longer for stability)
            'idle_timeout' => 300,             // 5 minutes (longer for reuse)
            'connect_timeout' => 5,            // 5 seconds to connect
            'socket_timeout' => 3              // 3 seconds for read/write
        ]);
    }

    /**
     * Submit formatted GPS data to WL server via pooled TCP connections
     */
    public function submitFormattedGPS(string $gpsData, string $wl_ip, int $wl_port, string $vehicle_id): array
    {
        $host = $wl_ip ?: "20.195.56.146";
        $port = $wl_port ?: 2199;
        
        // Use connection pool to send data with retry logic
        $result = $this->sendDataWithRetry($host, $port, $gpsData, $vehicle_id, 2); // Reduced retries
        
        if ($result['success']) {
            // Only log successful connections every 10th request to reduce log noise
            if (mt_rand(1, 10) === 1) {
                Log::channel('gpssuccesslog')->info([
                    'vehicle' => $vehicle_id,
                    'date' => now()->toISOString(),
                    'position' => preg_replace('/\s+/', '', $gpsData),
                    'response' => $result['response'] ?? '',
                    'connection_id' => $result['connection_id'] ?? 'unknown',
                    'bytes_written' => $result['bytes_written'] ?? 0,
                    'attempts' => $result['attempts'] ?? 1,
                    'process_id' => getmypid()
                ]);
            }
        } else {
            // Always log errors
            Log::channel('gpserrorlog')->error([
                'vehicle' => $vehicle_id,
                'date' => now()->toISOString(),
                'host' => $host,
                'port' => $port,
                'gps_data' => $gpsData,
                'error' => $result['error'],
                'attempts' => $result['attempts'] ?? 1,
                'process_id' => getmypid()
            ]);
        }
        
        return $result;
    }

    /**
     * Send data with retry logic for connection reset errors
     */
    private function sendDataWithRetry(string $host, int $port, string $data, string $vehicleId, int $maxRetries = 2): array
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < $maxRetries) {
            $attempt++;
            
            try {
                $result = ConnectionPoolManager::sendData($host, $port, $data, $vehicleId);
                
                if ($result['success']) {
                    $result['attempts'] = $attempt;
                    
                    // Log connection reuse success (reduced frequency)
                    if ($attempt === 1 && mt_rand(1, 20) === 1) {
                        Log::channel('gps_pool')->debug("GPS data sent successfully", [
                            'vehicle' => $vehicleId,
                            'process_id' => getmypid(),
                            'connection_reused' => isset($result['connection_id'])
                        ]);
                    }
                    
                    return $result;
                }
                
                $lastError = $result['error'];
                
                // Check if this is a recoverable error
                if (strpos($lastError, 'Connection reset by peer') !== false || 
                    strpos($lastError, 'unable to read from socket [104]') !== false) {
                    
                    if ($attempt < $maxRetries) {
                        // Shorter wait for connection reset errors
                        usleep(100000 * $attempt); // 100ms, 200ms
                        continue;
                    }
                }
                
                break;
                
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                
                if ($attempt < $maxRetries) {
                    usleep(50000 * $attempt);
                    continue;
                }
            }
        }

        return [
            'success' => false,
            'error' => "Failed after {$maxRetries} attempts. Last error: {$lastError}",
            'vehicle_id' => $vehicleId,
            'attempts' => $attempt,
            'process_id' => getmypid()
        ];
    }

    /**
     * Get connection pool statistics with process info
     */
    public function getPoolStats(): array
    {
        $stats = ConnectionPoolManager::getStats();
        
        // Add summary information
        $stats['summary'] = [
            'current_process' => getmypid(),
            'estimated_total_processes' => 14, // Based on your logs
            'estimated_total_connections' => $stats['total_local_connections'] * 14,
            'connection_efficiency' => $this->calculateEfficiency($stats)
        ];
        
        return $stats;
    }

    /**
     * Calculate connection efficiency
     */
    private function calculateEfficiency(array $stats): array
    {
        $efficiency = [
            'local_utilization' => 0,
            'reuse_ratio' => 0,
            'status' => 'unknown'
        ];
        
        if ($stats['total_local_connections'] > 0) {
            $efficiency['local_utilization'] = round(($stats['total_local_connections'] / 5) * 100, 1);
        }
        
        foreach ($stats['pools'] as $poolStats) {
            if ($poolStats['global_created'] > 0) {
                $efficiency['reuse_ratio'] = round($poolStats['global_reused'] / $poolStats['global_created'], 2);
                break;
            }
        }
        
        // Determine status
        if ($efficiency['reuse_ratio'] >= 5) {
            $efficiency['status'] = 'excellent';
        } elseif ($efficiency['reuse_ratio'] >= 2) {
            $efficiency['status'] = 'good';
        } elseif ($efficiency['reuse_ratio'] >= 1) {
            $efficiency['status'] = 'fair';
        } else {
            $efficiency['status'] = 'poor';
        }
        
        return $efficiency;
    }

    /**
     * Clean up old connections manually
     */
    public function cleanupPools(): array
    {
        $result = ConnectionPoolManager::cleanup();
        $result['cleanup_time'] = now()->toISOString();
        return $result;
    }

    /**
     * Health check for connection pools
     */
    public function healthCheck(): array
    {
        $stats = ConnectionPoolManager::getStats();
        $efficiency = $this->calculateEfficiency($stats);
        
        $health = [
            'status' => 'healthy',
            'timestamp' => now()->toISOString(),
            'process_id' => getmypid(),
            'local_pools' => $stats['local_pools'],
            'total_local_connections' => $stats['total_local_connections'],
            'efficiency' => $efficiency,
            'warnings' => [],
            'recommendations' => []
        ];
        
        // Check for potential issues
        foreach ($stats['pools'] as $poolKey => $poolStats) {
            if ($poolStats['local_connections'] >= 4) { // Near max of 5
                $health['warnings'][] = "Pool {$poolKey} is near capacity locally ({$poolStats['local_connections']}/5)";
            }
        }
        
        // Performance recommendations
        if ($efficiency['reuse_ratio'] < 1) {
            $health['recommendations'][] = "Low connection reuse - consider increasing idle_timeout";
        }
        
        if ($efficiency['local_utilization'] > 80) {
            $health['recommendations'][] = "High local utilization - consider increasing max_connections_per_pool";
        }
        
        if (!empty($health['warnings']) || !empty($health['recommendations'])) {
            $health['status'] = 'warning';
        }
        
        return $health;
    }

    /**
     * Test connection to a specific endpoint
     */
    public function testConnection(string $host, int $port): array
    {
        $testData = '$' . date('ymdHis') . ',1,0,0,0,0,0,0,0,0,10,0,0,TEST';
        $startTime = microtime(true);
        
        $result = ConnectionPoolManager::sendData($host, $port, $testData, 'TEST_VEHICLE');
        
        $responseTime = round((microtime(true) - $startTime) * 1000, 2); // ms
        
        return [
            'host' => $host,
            'port' => $port,
            'success' => $result['success'],
            'response_time_ms' => $responseTime,
            'error' => $result['error'] ?? null,
            'process_id' => getmypid(),
            'connection_id' => $result['connection_id'] ?? null
        ];
    }
}