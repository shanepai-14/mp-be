<?php

namespace App\Http\Controllers;

use App\Http\Response\ApiResponse;
use App\Models\Gps;
use App\Models\Vehicle;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Annotations as OA;

class GpsController extends Controller
{

    // ** GPS DATA FORMAT **
    //  0. MESSAGE ID (Uchar8) -> $ - location Report;  # - Event Report(TBC);
    //  1. TIMESTAMP (string) -> Position Timestamp in UTC (YYMMDDHHmmss);
    //  2. GPS (integer) -> 1 - Online;  0 - Offline;
    //  3. LATITUDE (float) -> Range: -90.000000 to 90.000000;
    //  4. LONGITUDE (float) -> Range: -180.000000 to 180.000000;
    //  5. AlTITUDE (integer) -> Integer in meter
    //  7. SPEED (integer) -> Integer in km/h;  Range: 0-999;
    //  7. COURSE (integer) -> Integer in degree;  Range: 0-359;
    //  8. SATELLITE COUNT (integer) -> No. of satellites
    //  9. ADC1 (float) -> Device Battery;
    //  10. ADC2 (float) -> Car Battery;
    //  11. IO STATUS (integer) -> HEX* value indicating INput and OUTput pin status;
    //  12. MILEAGE (integer) -> mileage in km;
    //  13. RPM (integer) -> Mixer drum RPM counter value;
    //  14. DEVICE ID (string) -> IMEI number or unique ID (Plate Number);
    //  15. END (Uchar8) -> \r

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
     *         description="successful operation",
     *         @OA\JsonContent(ref="#/components/schemas/User")
     *     ),
     *      @OA\Response(
     *          response=409,
     *          description="Unrecognized vehicle already exist!",
     *      ),
     *      @OA\Response(
     *          response=404,
     *          description="Company key/Vendor key does not exist!"
     *      ),
     *     @OA\Response(
     *          response=500,
     *          description="Internal Server Error",
     *      ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthenticated",
     *      ),
     * )
     */
    public function sendGPS(Request $request)
    {
        $this->validateInput($request);

        $vendor_id = Vendor::where('vendor_key', $request->CompanyKey)->value('id');
        $response = new ApiResponse();

        if ($vendor_id) {
            $isExist = Vehicle::where('device_id_plate_no', $request->Device_ID)->get();

            // If vehicle does not exist create vehicle with status unregistered and ignore gps data
            if ($isExist->value('id') == null) {
                $newVehicle = Vehicle::create([
                    'vehicle_status' => 3,
                    'device_id_plate_no' => $request->Device_ID,
                    'vendor_id' => $vendor_id,
                    'mileage' => $request->Mileage
                ]);

                if ($newVehicle)
                    return $response->SuccessResponse('Unrecognized vehicle is saved.', $newVehicle);

                return $response->ErrorResponse('Server Error', 500);
            }

            // Save GPS/Position data if vehicle exist and status is not unregistered
            else if ($isExist->value('vehicle_status') != 3) {
                // Add default value if these are missing in the payload
                $request->mergeIfMissing(['Drum_Status' => 0]);
                $request->mergeIfMissing(['RPM' => 0]);

                $transformedData = $isExist->value('vehicle_status') == 1 ? $this->dataTransformation($request->collect()) : null;

                // Save GPS Data to MongoDB
                $newGps = Gps::create([
                    'Vendor_Key' => $request->CompanyKey,
                    'Timestamp' => $request->Timestamp,
                    'GPS' => $request->GPS,
                    'Ignition' => $request->Ignition,
                    'Latitude' => $request->Latitude,
                    'Longitude' => $request->Longitude,
                    'Altitude' => $request->Altitude,
                    'Speed' => $request->Speed,
                    'Course' => $request->Course,
                    'Satellite_Count' => $request->Satellite_Count,
                    'ADC1' => $request->ADC1,
                    'ADC2' => $request->ADC2,
                    // 'Drum_Status' => $request->Drum_Status,
                    'Drum_Status' => 0,                             // Always ZERO  as of now
                    'Mileage' => $request->Mileage,
                    'RPM' => $request->RPM ?? 0,
                    'Device_ID' => $request->Device_ID,
                    'Position' => $transformedData
                ]);
              
                // Forward transformed GPS data to Wlocate
                $socketCtrl = new GPSSocketController();
                $socketCtrl->submitFormattedGPS($transformedData);

                if ($newGps) {
                    return $response->SuccessResponse('Position is successfully saved.', $request->collect());
                }

                return $response->ErrorResponse('Server Error', 500);
            }

            // If vehicle already exist and with status of unregistered
            else
                return $response->ErrorResponse('Unrecognized vehicle already exist!', 409);
        }

        return $response->ErrorResponse('Company key/Vendor key does not exist!', 404);
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

        // MySQL Database Server IP
        if ($MySQLsocket = $this->serverStatus('127.0.0.1:3306')) {
            // return $response->SuccessResponse('Server is online!', []);
            fclose($MySQLsocket);

            if ($mongoDBsocket = $this->serverStatus('127.0.0.1:27017')) {
                return $response->SuccessResponse('Server is online!', []);
                fclose($mongoDBsocket);
            }

            return $response->ErrorResponse('MongoDB Server is offline!', 500);
        }
        
        else
            return $response->ErrorResponse('MySQL Server is offline!', 500);
    }

    protected function serverStatus($url)
    {
        return @fsockopen($url, 80, $errno, $errstr, 30);
    }

    private function dataTransformation($data)
    {
        $gpsData = '$';
        $pattern = ['Timestamp', 'GPS', 'Latitude', 'Longitude', 'Altitude', 'Speed', 'Course', 'Satellite_Count', 'ADC1', 'ADC2', 'IO', 'Mileage', 'RPM', 'Device_ID'];

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

        return $gpsData . '\r';
    }

    private function ioStatusCalculation($ignition)
    {
        // X - 0
        // OUT1 - always Low (0)
        // OUT0 - always Low (0)
        // IN4 - always high (1)
        // IN3 - drum direction is always (0)
        // IN2 - always low (0)
        // IN1 - always Low (0)
        // IN0 - from vendor supplied value

        return $ignition == 0 ? 10 : 11;
    }

    private function validateInput($request)
    {
        return Validator::make($request->all(), [
            'CompanyKey' => ['required', 'string'],
            'Timestamp' => ['required', 'date'],
            'GPS' => ['required', 'boolean'],
            'Ignition' => ['required', 'boolean'],
            'Latitude' => ['required', 'numeric'],
            'Longitude' => ['required', 'numeric'],
            'Altitude' => ['required', 'integer'],
            'Speed' => ['required', 'integer', 'between:0,999'],
            'Course' => ['required', 'integer', 'between:0,359'],
            'Satellite_Count' => ['required', 'integer'],
            'ADC1' => ['required', 'numeric'],
            'ADC2' => ['required', 'numeric'],
            'Mileage' => ['required', 'integer'],
            'Drum_Status' => ['boolean'],
            'RPM' => ['integer'],
            'Device_ID' => ['required', 'string'],
        ])->validate();
    }
}
