<?php

namespace App\Http\Controllers;

use App\Http\Response\ApiResponse;
use App\Models\CurrentCustomer;
use App\Models\Gps;
use App\Models\Vehicle;
use App\Models\Transporter;
use App\Models\VehicleAssignment;
use App\Services\SocketPool\Client\SocketPoolClient;
use App\Services\ConnectionPoolService;
use App\Services\SocketPool\Exceptions\SocketPoolException;
use App\Services\SocketPool\Exceptions\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use OpenApi\Annotations as OA;

class GpsController extends Controller
{
    private SocketPoolClient $socketPoolClient;

    public function __construct(SocketPoolClient $socketPoolClient)
    {
        $this->socketPoolClient = $socketPoolClient;
    }

    /**
     * @OA\Post(
     *     path="/position",
     *     tags={"Position"},
     *     summary="Send GPS Position",
     *     operationId="SendGPS",
     * @OA\RequestBody(
     *         description="GPS Position Information",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(ref="#/components/schemas/Gps")
     *         )
     *     ),
     * @OA\Response(
     *         response=200,
     *         description="Success",
     *     ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthorized User",
     *      ),
     *      @OA\Response(
     *          response=404,
     *          description="Vendor key not found"
     *      ),
     *      @OA\Response(
     *          response=409,
     *          description="Vehicle is not registered",
     *      ),
     *     @OA\Response(
     *          response=500,
     *          description="Internal Server Error",
     *      ),
     * )
     */
    public function sendGPS(Request $request)
    {
        $response = new ApiResponse();

        // Validate input
        $validator = $this->validateInput($request);
        if ($validator->fails()) {
            return $response->ErrorResponse($validator->errors(), 400);
        }

        // Rate limiting - 2 requests per 30 seconds per vehicle
        $key = "position_rate_limit_key_{$request->Vehicle_ID}";
        if (RateLimiter::tooManyAttempts($key, 2)) {
            return $response->ErrorResponse("Too many requests", 429);
        }
        RateLimiter::hit($key, 30);

        // Cache transporter lookup for 5 minutes
        $transporter = Cache::remember(
            "transporter_{$request->CompanyKey}",
            300,
            fn() => Transporter::where('transporter_key', $request->CompanyKey)->first()
        );

        if (!$transporter) {
            return $response->ErrorResponse("Vendor key not found", 404);
        }

        // Cache vehicle lookup for 5 minutes
        $vehicle = Cache::remember(
            "vehicle_{$request->Vehicle_ID}",
            300,
            fn() => Vehicle::where('device_id_plate_no', $request->Vehicle_ID)->first()
        );

        if (!$vehicle) {
            return $response->SuccessResponse('Vehicle is not registered', '');
        }

        // Get latest vehicle assignment (cache for 1 minute)
        $vehicleAssignment = Cache::remember(
            "vehicle_assignment_{$vehicle->id}",
            60,
            fn() => VehicleAssignment::where('vehicle_id', $vehicle->id)->latest('id')->first()
        );

        if ($vehicleAssignment->vehicle_status == 3) {
            return $response->SuccessResponse('Vehicle is not registered', '');
        }

        // Process GPS data
        $this->processGPSData($request, $vehicleAssignment);

        return $response->SuccessResponse('Success', null);
    }

    /**
     * Process GPS data and forward to WLocate if needed
     */
    private function processGPSData(Request $request, VehicleAssignment $vehicleAssignment): void
    {
        // Set default values for missing fields
        $this->setDefaultValues($request);

        // Prepare GPS data for database
        $gpsData = $this->prepareGPSData($request, $vehicleAssignment);

        // Save GPS data to database
        $this->saveGPSData($gpsData);

        // Forward to WLocate if vehicle is active
        if ($vehicleAssignment->vehicle_status == 1) {
            $this->forwardToWLocate($request, $vehicleAssignment);
        }
    }

    /**
     * Set default values for missing GPS fields
     */
    private function setDefaultValues(Request $request): void
    {
        $defaults = [
            'Drum_Status' => 0,
            'RPM' => 0,
            'ADC1' => 0,
            'ADC2' => 0,
            'Satellite_Count' => 0,
            'GPS' => 1  // Temporarily set GPS to 1
        ];

        foreach ($defaults as $key => $value) {
            if (!$request->has($key)) {
                $request->merge([$key => $value]);
            }
        }
    }

    /**
     * Prepare GPS data array for database storage
     */
    private function prepareGPSData(Request $request, VehicleAssignment $vehicleAssignment): array
    {
        $transformedData = null;
        
        if ($vehicleAssignment->vehicle_status == 1) {
            $transformedData = $this->dataTransformation($request->all());
        }

        return [
            'Vendor_Key' => $request->CompanyKey,
            'Vehicle_ID' => $request->Vehicle_ID,
            'Timestamp' => $request->Timestamp,
            'GPS' => intval($request->GPS),
            'Ignition' => intval($request->Ignition),
            'Latitude' => $request->Latitude,
            'Longitude' => $request->Longitude,
            'Altitude' => $request->Altitude,
            'Speed' => $request->Speed,
            'Course' => $request->Course,
            'Mileage' => $request->Mileage,
            'Satellite_Count' => intval($request->Satellite_Count),
            'ADC1' => $request->ADC1,
            'ADC2' => $request->ADC2,
            'Drum_Status' => $request->Drum_Status,
            'RPM' => $request->RPM,
            'Position' => $transformedData
        ];
    }

    /**
     * Save GPS data to MongoDB
     */
    private function saveGPSData(array $gpsData): void
    {
        try {
            Log::channel('custom_log')->info('Storing new request data', [$gpsData]);
            Gps::create($gpsData);
        } catch (\Exception $e) {
            Log::channel('custom_log')->error('GPS save failed', [
                'data' => $gpsData,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Forward GPS data to WLocate using Socket Pool Service
     */
    // private function forwardToWLocate(Request $request, VehicleAssignment $vehicleAssignment): void
    // {
    //     $currentCustomer = CurrentCustomer::with(['ipport'])
    //         ->where('vehicle_assignment_id', $vehicleAssignment->id)
    //         ->first();

    //     if (!$currentCustomer || !$currentCustomer->ipport) {
    //         return;
    //     }

    //     $transformedData = $this->dataTransformation($request->all());
    //     $requestId = Str::uuid();
        
    //     try {
    //         // Send GPS data using Socket Pool Service
    //         $result = $this->socketPoolClient->sendGpsData(
    //             $transformedData,
    //             $currentCustomer->ipport->ip,
    //             $currentCustomer->ipport->port,
    //             $request->Vehicle_ID,
    //             [
    //                 'request_id' => $requestId,
    //                 'priority' => 'high',
    //                 'timeout' => 5
    //             ]
    //         );
            
    //         // Log results based on success/failure
    //         if ($result['success']) {
    //             Log::channel('gpssuccesslog')->info([
    //                 'vehicle' => $request->Vehicle_ID,
    //                 'date' => now()->toISOString(),
    //                 'position' => preg_replace('/\s+/', '', $transformedData),
    //                 'response' => $result['response'] ?? '',
    //                 'request_id' => $result['request_id'] ?? $requestId,
    //                 'bytes_sent' => $result['bytes_sent'] ?? 0,
    //                 'hex_response' => $result['hex_response'] ?? '',
    //                 'process_id' => getmypid(),
    //                 'ip' => $currentCustomer->ipport->ip,
    //                 'port' => $currentCustomer->ipport->port,
    //                 'duration_ms' => $result['processing_time'] ?? $result['duration'] ?? 0,
    //                 'socket_pool_used' => true,
    //                 'timestamp' => $result['timestamp'] ?? time(),
    //                 'vehicle_id' => $result['vehicle_id'] ?? $request->Vehicle_ID
    //             ]);
                
    //         } else {
    //             // Log failure with Socket Pool context
    //             Log::channel('gpserrorlog')->error([
    //                 'vehicle' => $request->Vehicle_ID,
    //                 'date' => now()->toISOString(),
    //                 'host' => $currentCustomer->ipport->ip,
    //                 'port' => $currentCustomer->ipport->port,
    //                 'gps_data' => $transformedData,
    //                 'error' => $result['error'] ?? 'Unknown error',
    //                 'request_id' => $result['request_id'] ?? $requestId,
    //                 'process_id' => getmypid(),
    //                 'duration_ms' => $result['processing_time'] ?? $result['duration'] ?? 0,
    //                 'socket_pool_used' => true,
    //                 'service_running' => $this->socketPoolClient->isServiceRunning()
    //             ]);
    //         }
            
    //     } catch (SocketPoolException $e) {
    //         // Handle Socket Pool specific exceptions
    //         Log::channel('gpserrorlog')->error([
    //             'vehicle' => $request->Vehicle_ID,
    //             'date' => now()->toISOString(),
    //             'host' => $currentCustomer->ipport->ip,
    //             'port' => $currentCustomer->ipport->port,
    //             'gps_data' => $transformedData,
    //             'error' => 'Socket Pool Error: ' . $e->getMessage(),
    //             'request_id' => $requestId,
    //             'process_id' => getmypid(),
    //             'exception_type' => get_class($e),
    //             'socket_pool_used' => true,
    //             'service_running' => $this->socketPoolClient->isServiceRunning()
    //         ]);
            
    //     } catch (ConnectionException $e) {
    //         // Handle connection specific exceptions
    //         Log::channel('gpserrorlog')->error([
    //             'vehicle' => $request->Vehicle_ID,
    //             'date' => now()->toISOString(),
    //             'host' => $currentCustomer->ipport->ip,
    //             'port' => $currentCustomer->ipport->port,
    //             'gps_data' => $transformedData,
    //             'error' => 'Connection Error: ' . $e->getMessage(),
    //             'request_id' => $requestId,
    //             'process_id' => getmypid(),
    //             'exception_type' => get_class($e),
    //             'socket_pool_used' => true,
    //             'service_running' => $this->socketPoolClient->isServiceRunning()
    //         ]);
            
    //     } catch (\Exception $e) {
    //         // Handle any other exceptions
    //         Log::channel('gpserrorlog')->error([
    //             'vehicle' => $request->Vehicle_ID,
    //             'date' => now()->toISOString(),
    //             'host' => $currentCustomer->ipport->ip,
    //             'port' => $currentCustomer->ipport->port,
    //             'gps_data' => $transformedData,
    //             'error' => 'Unexpected Error: ' . $e->getMessage(),
    //             'request_id' => $requestId,
    //             'process_id' => getmypid(),
    //             'exception_type' => get_class($e),
    //             'socket_pool_used' => true,
    //             'service_running' => false // Assume service is down on unexpected errors
    //         ]);
    //     }
    // }

     private function forwardToWLocate(Request $request, VehicleAssignment $vehicleAssignment): void
    {
        $currentCustomer = CurrentCustomer::with(['ipport'])
            ->where('vehicle_assignment_id', $vehicleAssignment->id)
            ->first();

        if (!$currentCustomer || !$currentCustomer->ipport) {
            return;
        }

        $transformedData = $this->dataTransformation($request->all());
        
        // Try to send GPS data with aggressive connection creation
        $result = ConnectionPoolService::sendGPSData(
            $currentCustomer->ipport->ip,
            $currentCustomer->ipport->port,
            $transformedData,
            $request->Vehicle_ID
        );
        
        // Log results based on success/failure
        if ($result['success']) {
            Log::channel('gpssuccesslog')->info([
                'vehicle' => $request->Vehicle_ID,
                'date' => now()->toISOString(),
                'position' => preg_replace('/\s+/', '', $transformedData),
                'response' => $result['response'] ?? '',
                'connection_id' => $result['connection_id'] ?? 'unknown',
                'bytes_written' => $result['bytes_written'] ?? 0,
                'attempts' => $result['attempts'] ?? 1,
                'connection_reused' => $result['reused'] ?? false,
                'process_id' => getmypid(),
                'ip' => $currentCustomer->ipport->ip,
                'port' => $currentCustomer->ipport->port,
                'duration_ms' => $result['duration_ms'] ?? 0,
                'emergency_mode' => $result['emergency_mode'] ?? false,
                'direct_connection' => $result['direct_connection'] ?? false,
                'force_created' => $result['force_created'] ?? false
            ]);
            
        } else {
            // Log failure but keep trying - no circuit breaker
            Log::channel('gpserrorlog')->error([
                'vehicle' => $request->Vehicle_ID,
                'date' => now()->toISOString(),
                'host' => $currentCustomer->ipport->ip,
                'port' => $currentCustomer->ipport->port,
                'gps_data' => $transformedData,
                'error' => $result['error'],
                'attempts' => $result['attempts'] ?? 1,
                'process_id' => getmypid(),
                'duration_ms' => $result['duration_ms'] ?? 0,
                'emergency_mode' => $result['emergency_mode'] ?? false,
                'consecutive_failures' => $result['consecutive_failures'] ?? 0
            ]);
        }
    }

    /**
     * @OA\Post(
     *     path="/check-server",
     *     tags={"Position"},
     *     summary="Check Server Status",
     *     operationId="CheckServer",
     *     @OA\Response(
     *         response="default",
     *         description="successful operation"
     *     )
     * )
     */
    public function checkServer()
    {
        $response = new ApiResponse();

        // MySQL Database Server Check
        if ($MySQLsocket = $this->serverStatus('127.0.0.1', 3306)) {
            fclose($MySQLsocket);

            // MongoDB Server Check
            if ($mongoDBsocket = $this->serverStatus('127.0.0.1', 27017)) {
                fclose($mongoDBsocket);
                
                // Check Socket Pool Service status
                $socketPoolStatus = $this->checkSocketPoolService();
                
                return $response->SuccessResponse('Server is online!', [
                    'mysql' => 'online',
                    'mongodb' => 'online',
                    'socket_pool' => $socketPoolStatus
                ]);
            }

            return $response->ErrorResponse('MongoDB Server is offline!', 500);
        }

        return $response->ErrorResponse('MySQL Server is offline!', 500);
    }

    /**
     * Check Socket Pool Service status
     */
    private function checkSocketPoolService(): array
    {
        try {
            $isRunning = $this->socketPoolClient->isServiceRunning();
            
            if ($isRunning) {
                $health = $this->socketPoolClient->performHealthCheck();
                $stats = $this->socketPoolClient->getConnectionStats();
                
                return [
                    'status' => 'online',
                    'healthy' => $health['success'] ?? false,
                    'pool_size' => $stats['data']['pool_size'] ?? 0,
                    'active_connections' => count($stats['data']['active_connections'] ?? []),
                    'instance_id' => substr($health['instance_id'] ?? 'unknown', 0, 8)
                ];
            }
            
            return [
                'status' => 'offline',
                'healthy' => false,
                'message' => 'Socket Pool Service is not running'
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'healthy' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get Socket Pool Service statistics
     * 
     * @OA\Get(
     *     path="/socket-pool/stats",
     *     tags={"Position"},
     *     summary="Get Socket Pool Statistics",
     *     operationId="GetSocketPoolStats",
     *     @OA\Response(
     *         response=200,
     *         description="Socket Pool Statistics",
     *     )
     * )
     */
    public function getSocketPoolStats()
    {
        $response = new ApiResponse();
        
        try {
            if (!$this->socketPoolClient->isServiceRunning()) {
                return $response->ErrorResponse('Socket Pool Service is not running', 503);
            }
            
            $stats = $this->socketPoolClient->getConnectionStats();
            $metrics = $this->socketPoolClient->getMetrics();
            $health = $this->socketPoolClient->performHealthCheck();
            
            return $response->SuccessResponse('Socket Pool Statistics', [
                'service_status' => 'running',
                'health' => $health,
                'statistics' => $stats,
                'metrics' => $metrics
            ]);
            
        } catch (\Exception $e) {
            return $response->ErrorResponse('Failed to get Socket Pool statistics: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Test GPS connection to specific server
     * 
     * @OA\Post(
     *     path="/test-gps-connection",
     *     tags={"Position"},
     *     summary="Test GPS Connection",
     *     operationId="TestGpsConnection",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"host", "port"},
     *             @OA\Property(property="host", type="string", example="localhost"),
     *             @OA\Property(property="port", type="integer", example=2199)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Connection Test Result",
     *     )
     * )
     */
    public function testGpsConnection(Request $request)
    {
        $response = new ApiResponse();
        
        $validator = Validator::make($request->all(), [
            'host' => 'required|string',
            'port' => 'required|integer|min:1|max:65535'
        ]);
        
        if ($validator->fails()) {
            return $response->ErrorResponse($validator->errors(), 400);
        }
        
        try {
            $result = $this->socketPoolClient->testConnection(
                $request->input('host'),
                $request->input('port')
            );
            
            return $response->SuccessResponse('Connection Test Result', $result);
            
        } catch (\Exception $e) {
            return $response->ErrorResponse('Connection test failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Close specific connection in the pool
     * 
     * @OA\Delete(
     *     path="/socket-pool/connection",
     *     tags={"Position"},
     *     summary="Close Socket Pool Connection",
     *     operationId="CloseSocketPoolConnection",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"host", "port"},
     *             @OA\Property(property="host", type="string", example="localhost"),
     *             @OA\Property(property="port", type="integer", example=2199)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Connection Closed",
     *     )
     * )
     */
    public function closeSocketPoolConnection(Request $request)
    {
        $response = new ApiResponse();
        
        $validator = Validator::make($request->all(), [
            'host' => 'required|string',
            'port' => 'required|integer|min:1|max:65535'
        ]);
        
        if ($validator->fails()) {
            return $response->ErrorResponse($validator->errors(), 400);
        }
        
        try {
            $result = $this->socketPoolClient->closeConnection(
                $request->input('host'),
                $request->input('port')
            );
            
            return $response->SuccessResponse('Connection management result', $result);
            
        } catch (\Exception $e) {
            return $response->ErrorResponse('Failed to close connection: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Batch send GPS data for multiple vehicles
     * 
     * @OA\Post(
     *     path="/batch-gps",
     *     tags={"Position"},
     *     summary="Batch Send GPS Data",
     *     operationId="BatchSendGPS",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"gps_data"},
     *             @OA\Property(
     *                 property="gps_data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="vehicle_id", type="string"),
     *                     @OA\Property(property="host", type="string"),
     *                     @OA\Property(property="port", type="integer"),
     *                     @OA\Property(property="gps_data", type="string")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Batch Processing Result",
     *     )
     * )
     */
    public function batchSendGPS(Request $request)
    {
        $response = new ApiResponse();
        
        $validator = Validator::make($request->all(), [
            'gps_data' => 'required|array|min:1',
            'gps_data.*.vehicle_id' => 'required|string',
            'gps_data.*.host' => 'required|string',
            'gps_data.*.port' => 'required|integer|min:1|max:65535',
            'gps_data.*.gps_data' => 'required|string'
        ]);
        
        if ($validator->fails()) {
            return $response->ErrorResponse($validator->errors(), 400);
        }
        
        try {
            $result = $this->socketPoolClient->batchSendGpsData($request->input('gps_data'));
            
            return $response->SuccessResponse('Batch GPS processing completed', $result);
            
        } catch (\Exception $e) {
            return $response->ErrorResponse('Batch processing failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Check server status using socket connection
     */
    protected function serverStatus(string $host, int $port)
    {
        return @fsockopen($host, $port, $errno, $errstr, 30);
    }

    /**
     * Transform GPS data to WLocate format
     */
    private function dataTransformation(array $data): string
    {
        $gpsData = '$';
        $pattern = [
            'Timestamp', 'GPS', 'Latitude', 'Longitude', 'Altitude', 
            'Speed', 'Course', 'Satellite_Count', 'ADC1', 'ADC2', 
            'IO', 'Mileage', 'RPM', 'Vehicle_ID'
        ];

        foreach ($pattern as $patternVal) {
            switch ($patternVal) {
                case 'Timestamp':
                    $dateFormatted = date_create($data[$patternVal]);
                    $gpsData .= date_format($dateFormatted, "ymdHis");
                    break;
                case 'IO':
                    $gpsData .= ',' . $this->ioStatusCalculation($data['Ignition']);
                    break;
                default:
                    $gpsData .= ',' . $data[$patternVal];
                    break;
            }
        }

        return $gpsData;
    }

    /**
     * Calculate IO status based on ignition
     */
    private function ioStatusCalculation(int $ignition): int
    {
        // X - 0
        // OUT1 - always Low (0)
        // OUT0 - always Low (0)
        // IN4 - always high (1)
        // IN3 - drum direction is always (0)
        // IN2 - always low (0)
        // IN1 - always Low (0)
        // IN0 - from transporter/vendor supplied value
        return $ignition == 0 ? 10 : 11;
    }

    /**
     * Validate GPS input data
     */
    private function validateInput(Request $request)
    {
        return Validator::make($request->all(), [
            'CompanyKey' => ['required', 'string'],
            'Vehicle_ID' => ['required', 'string'],
            'Timestamp' => ['required', 'date'],
            'GPS' => ['required', 'integer', 'min:0', 'max:1'],
            'Ignition' => ['required', 'integer', 'min:0', 'max:1'],
            'Latitude' => ['required', 'numeric'],
            'Longitude' => ['required', 'numeric'],
            'Altitude' => ['required', 'numeric', 'min:0'],
            'Speed' => ['required', 'integer', 'between:0,999'],
            'Course' => ['required', 'integer', 'between:0,359'],
            'Mileage' => ['required', 'integer'],
            'Satellite_Count' => ['integer'],
            'ADC1' => ['numeric'],
            'ADC2' => ['numeric'],
            'Drum_Status' => ['integer', 'min:0', 'max:2'],
            'RPM' => ['integer'],
        ]);
    }
}