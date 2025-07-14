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
        
        // Use connection pool to send data
        $result = ConnectionPoolManager::sendData($host, $port, $gpsData, $vehicle_id);
        
        if ($result['success']) {
            Log::channel('gpssuccesslog')->info([
                'vehicle' => $vehicle_id,
                'date' => now()->toISOString(),
                'position' => preg_replace('/\s+/', '', $gpsData),
                'response' => $result['response'],
                'connection_id' => $result['connection_id'],
                'bytes_written' => $result['bytes_written']
            ]);
        } else {
            Log::channel('gpserrorlog')->error([
                'vehicle' => $vehicle_id,
                'date' => now()->toISOString(),
                'host' => $host,
                'port' => $port,
                'gps_data' => $gpsData,
                'error' => $result['error']
            ]);
        }
        
        return $result;
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