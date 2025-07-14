<?php

namespace App\Http\Controllers;

use App\Services\ConnectionPoolService;
use App\Models\ConnectionPoolStat;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PooledGPSSocketController extends Controller
{
    /**
     * Get connection pool statistics
     * 
     * @OA\Get(
     *     path="/gps/pool/stats",
     *     tags={"GPS Pool Management"},
     *     summary="Get Connection Pool Statistics",
     *     @OA\Response(response=200, description="Pool statistics")
     * )
     */
    public function getPoolStats(): JsonResponse
    {
        $stats = ConnectionPoolService::getStats();
        
        return response()->json([
            'success' => true,
            'data' => $stats,
            'timestamp' => now()->toISOString()
        ]);
    }

    /**
     * Get connection pool analytics
     * 
     * @OA\Get(
     *     path="/gps/pool/analytics",
     *     tags={"GPS Pool Management"},
     *     summary="Get Connection Pool Analytics",
     *     @OA\Response(response=200, description="Pool analytics")
     * )
     */
    public function getAnalytics(): JsonResponse
    {
        $analytics = ConnectionPoolService::getAnalytics();
        
        return response()->json([
            'success' => true,
            'data' => $analytics,
            'timestamp' => now()->toISOString()
        ]);
    }

    /**
     * Perform connection pool cleanup
     * 
     * @OA\Post(
     *     path="/gps/pool/cleanup",
     *     tags={"GPS Pool Management"},
     *     summary="Cleanup Old Connections",
     *     @OA\Response(response=200, description="Cleanup results")
     * )
     */
    public function cleanupPools(): JsonResponse
    {
        $result = ConnectionPoolService::cleanup();
        
        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => "Cleaned up {$result['connections_removed']} connections",
            'timestamp' => now()->toISOString()
        ]);
    }

    /**
     * Health check for connection pools
     * 
     * @OA\Get(
     *     path="/gps/pool/health",
     *     tags={"GPS Pool Management"},
     *     summary="Connection Pool Health Check",
     *     @OA\Response(response=200, description="Health status")
     * )
     */
    public function healthCheck(): JsonResponse
    {
        $stats = ConnectionPoolService::getStats();
        $analytics = ConnectionPoolService::getAnalytics();
        
        $health = [
            'status' => 'healthy',
            'timestamp' => now()->toISOString(),
            'process_id' => getmypid(),
            'pool_type' => 'eloquent_mongodb',
            'local_connections' => $stats['local_connections'],
            'warnings' => [],
            'recommendations' => []
        ];
        
        // Determine health status
        if (isset($analytics['overall_reuse_ratio'])) {
            $reuseRatio = $analytics['overall_reuse_ratio'];
            
            if ($reuseRatio < 1) {
                $health['warnings'][] = "Low connection reuse ratio: {$reuseRatio}";
                $health['recommendations'][] = "Consider increasing idle timeout or reducing process restarts";
            }
            
            if ($reuseRatio >= 3) {
                $health['efficiency'] = 'excellent';
            } elseif ($reuseRatio >= 2) {
                $health['efficiency'] = 'good';
            } elseif ($reuseRatio >= 1) {
                $health['efficiency'] = 'fair';
            } else {
                $health['efficiency'] = 'poor';
                $health['status'] = 'warning';
            }
        }
        
        // Check success rate
        if (isset($analytics['overall_success_rate'])) {
            $successRate = $analytics['overall_success_rate'];
            
            if ($successRate < 90) {
                $health['warnings'][] = "Low success rate: {$successRate}%";
                $health['status'] = 'warning';
            }
        }
        
        return response()->json([
            'success' => true,
            'data' => $health,
            'analytics' => $analytics
        ]);
    }

    /**
     * Test connection to a specific endpoint
     * 
     * @OA\Post(
     *     path="/gps/pool/test",
     *     tags={"GPS Pool Management"},
     *     summary="Test Connection to Endpoint",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"host", "port"},
     *             @OA\Property(property="host", type="string", example="10.21.14.8"),
     *             @OA\Property(property="port", type="integer", example=1403)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Test results")
     * )
     */
    public function testConnection(Request $request): JsonResponse
    {
        $request->validate([
            'host' => 'required|string',
            'port' => 'required|integer|min:1|max:65535'
        ]);
        
        $host = $request->input('host');
        $port = $request->input('port');
        
        $testData = '$' . date('ymdHis') . ',1,0,0,0,0,0,0,0,0,10,0,0,TEST';
        $startTime = microtime(true);
        
        $result = ConnectionPoolService::sendGPSData($host, $port, $testData, 'TEST_VEHICLE');
        
        $responseTime = round((microtime(true) - $startTime) * 1000, 2);
        
        return response()->json([
            'success' => $result['success'],
            'data' => [
                'host' => $host,
                'port' => $port,
                'response_time_ms' => $responseTime,
                'connection_reused' => $result['reused'] ?? false,
                'error' => $result['error'] ?? null,
                'process_id' => getmypid(),
                'test_data_sent' => $testData,
                'response_received' => $result['response'] ?? ''
            ],
            'timestamp' => now()->toISOString()
        ]);
    }

    /**
     * Get global pool statistics across all processes
     * 
     * @OA\Get(
     *     path="/gps/pool/global-stats",
     *     tags={"GPS Pool Management"},
     *     summary="Get Global Pool Statistics",
     *     @OA\Response(response=200, description="Global statistics")
     * )
     */
    public function getGlobalStats(): JsonResponse
    {
        try {
            $globalStats = ConnectionPoolStat::getAllPoolsStats();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'pools' => $globalStats,
                    'summary' => [
                        'total_pools' => count($globalStats),
                        'total_requests' => array_sum(array_column($globalStats, 'total_success')) + 
                                          array_sum(array_column($globalStats, 'total_send_failed')),
                        'average_reuse_ratio' => count($globalStats) > 0 ? 
                            round(array_sum(array_column($globalStats, 'reuse_ratio')) / count($globalStats), 2) : 0
                    ]
                ],
                'timestamp' => now()->toISOString()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }

    /**
     * Clean up old statistics records
     * 
     * @OA\Delete(
     *     path="/gps/pool/stats/cleanup",
     *     tags={"GPS Pool Management"},
     *     summary="Cleanup Old Statistics",
     *     @OA\Parameter(
     *         name="days",
     *         in="query",
     *         description="Number of days to keep (default: 7)",
     *         @OA\Schema(type="integer", minimum=1, maximum=30, default=7)
     *     ),
     *     @OA\Response(response=200, description="Cleanup results")
     * )
     */
    public function cleanupStats(Request $request): JsonResponse
    {
        $days = $request->input('days', 7);
        
        if ($days < 1 || $days > 30) {
            return response()->json([
                'success' => false,
                'error' => 'Days must be between 1 and 30'
            ], 400);
        }
        
        try {
            $deletedCount = ConnectionPoolStat::cleanupOldStats($days);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'deleted_records' => $deletedCount,
                    'days_kept' => $days
                ],
                'message' => "Cleaned up {$deletedCount} old statistics records",
                'timestamp' => now()->toISOString()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }
}