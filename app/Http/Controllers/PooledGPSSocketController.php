<?php

namespace App\Http\Controllers;

use App\Services\ConnectionPoolManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PooledGPSSocketController extends Controller
{
    public function __construct()
    {
        // Initialize connection pool with optimized settings
        ConnectionPoolManager::init([
            'max_connections_per_pool' => 20,  // Increase for high volume
            'connection_timeout' => 300,       // 5 minutes
            'idle_timeout' => 60,              // 1 minute
            'connect_timeout' => 3,            // 3 seconds to connect
            'socket_timeout' => 2              // 2 seconds for read/write
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
        $result = $this->sendDataWithRetry($host, $port, $gpsData, $vehicle_id, 3);
        
        if ($result['success']) {
            Log::channel('gpssuccesslog')->info([
                'vehicle' => $vehicle_id,
                'date' => now()->toISOString(),
                'position' => preg_replace('/\s+/', '', $gpsData),
                'response' => $result['response'] ?? '',
                'connection_id' => $result['connection_id'] ?? 'unknown',
                'bytes_written' => $result['bytes_written'] ?? 0,
                'attempts' => $result['attempts'] ?? 1
            ]);
        } else {
            Log::channel('gpserrorlog')->error([
                'vehicle' => $vehicle_id,
                'date' => now()->toISOString(),
                'host' => $host,
                'port' => $port,
                'gps_data' => $gpsData,
                'error' => $result['error'],
                'attempts' => $result['attempts'] ?? 1
            ]);
        }
        
        return $result;
    }

    /**
     * Send data with retry logic for connection reset errors
     */
    private function sendDataWithRetry(string $host, int $port, string $data, string $vehicleId, int $maxRetries = 3): array
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < $maxRetries) {
            $attempt++;
            
            try {
                $result = ConnectionPoolManager::sendData($host, $port, $data, $vehicleId);
                
                if ($result['success']) {
                    // Add attempt info to successful result
                    $result['attempts'] = $attempt;
                    return $result;
                }
                
                $lastError = $result['error'];
                
                // Check if this is a recoverable error
                if (strpos($lastError, 'Connection reset by peer') !== false || 
                    strpos($lastError, 'unable to read from socket [104]') !== false) {
                    
                    if ($attempt < $maxRetries) {
                        // Wait before retry for connection reset errors
                        usleep(200000 * $attempt); // Exponential backoff: 200ms, 400ms, 600ms
                        continue;
                    }
                }
                
                // For other errors, fail faster
                break;
                
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                
                if ($attempt < $maxRetries) {
                    usleep(100000 * $attempt); // Wait before retry
                    continue;
                }
            }
        }

        // All retries failed
        return [
            'success' => false,
            'error' => "Failed after {$maxRetries} attempts. Last error: {$lastError}",
            'vehicle_id' => $vehicleId,
            'attempts' => $attempt
        ];
    }

    /**
     * Get connection pool statistics
     */
    public function getPoolStats(): array
    {
        return ConnectionPoolManager::getStats();
    }

    /**
     * Clean up old connections manually
     */
    public function cleanupPools(): array
    {
        return ConnectionPoolManager::cleanup();
    }

    /**
     * Health check for connection pools
     */
    public function healthCheck(): array
    {
        $stats = ConnectionPoolManager::getStats();
        
        $health = [
            'status' => 'healthy',
            'timestamp' => now()->toISOString(),
            'pools' => $stats['total_pools'],
            'total_connections' => $stats['total_connections'],
            'active_connections' => $stats['total_active'],
            'warnings' => []
        ];
        
        // Check for potential issues
        foreach ($stats['pools'] as $poolKey => $poolStats) {
            if ($poolStats['active_connections'] >= $poolStats['total_connections'] * 0.9) {
                $health['warnings'][] = "Pool {$poolKey} is near capacity";
            }
            
            if ($poolStats['reuse_ratio'] < 2) {
                $health['warnings'][] = "Pool {$poolKey} has low reuse ratio: {$poolStats['reuse_ratio']}";
            }
        }
        
        if (!empty($health['warnings'])) {
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
        
        $result = ConnectionPoolManager::sendData($host, $port, $testData, 'TEST_VEHICLE');
        
        return [
            'host' => $host,
            'port' => $port,
            'success' => $result['success'],
            'response_time' => microtime(true),
            'error' => $result['error'] ?? null
        ];
    }
}