<?php

namespace App\Http\Controllers;

use App\Http\Response\ApiResponse;
use App\Models\CurrentCustomer;
use App\Models\Gps;
use App\Models\Vehicle;
use App\Models\Transporter;
use App\Models\VehicleAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use OpenApi\Annotations as OA;

class GpsController extends Controller
{
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
     * Forward GPS data to WLocate using connection pool
     */
    private function forwardToWLocate(Request $request, VehicleAssignment $vehicleAssignment): void
    {
        $currentCustomer = CurrentCustomer::with(['ipport'])
            ->where('vehicle_assignment_id', $vehicleAssignment->id)
            ->first();

        if ($currentCustomer && $currentCustomer->ipport) {
            $transformedData = $this->dataTransformation($request->all());
            
            // Use pooled connection for GPS forwarding
            $socketCtrl = new PooledGPSSocketController();
            $socketCtrl->submitFormattedGPS(
                $transformedData,
                $currentCustomer->ipport->ip,
                $currentCustomer->ipport->port,
                $request->Vehicle_ID
            );
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
                return $response->SuccessResponse('Server is online!', []);
            }

            return $response->ErrorResponse('MongoDB Server is offline!', 500);
        }

        return $response->ErrorResponse('MySQL Server is offline!', 500);
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