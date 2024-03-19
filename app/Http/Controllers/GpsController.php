<?php

namespace App\Http\Controllers;

use App\Http\Response\ApiResponse;
use App\Models\Gps;
use App\Models\Vehicle;
use App\Models\Transporter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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
     *         @OA\JsonContent(ref="#/components/schemas/User")
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
        $successList = new Collection();
        $failedList = new Collection();

        // VALIDATE THE INPUT FIRST
        foreach ($request->collect() as $item) {
            $this->validateInput($item);
        }

        $request->collect()->each(function ($gpsInput) use ($successList, $failedList) {
            $transporter = Transporter::where('transporter_key', $gpsInput['CompanyKey']);
            $response = new ApiResponse();

            if ($transporter->value('id')) {
                $isExist = Vehicle::where('device_id_plate_no', $gpsInput['Device_ID'])->get();

                // If vehicle does not exist create vehicle with status unregistered and ignore gps data
                if ($isExist->value('id') == null) {
                    $newVehicle = Vehicle::create([
                        'vehicle_status' => 3,
                        'device_id_plate_no' => $gpsInput['Device_ID'],
                        'transporter_id' => $transporter->value('id'),
                        // 'mileage' => $gpsInput['Mileage']
                    ]);

                    if ($newVehicle)
                        $successList->push($gpsInput);

                    else
                        $response->ErrorResponse('Server Error', 500);
                }

                // Save GPS/Position data if vehicle exist and status is not unregistered
                else if ($isExist->value('vehicle_status') != 3) {
                    // Add default value if these are missing in the payload
                    if (!array_key_exists('Drum_Status', $gpsInput))  $gpsInput['Drum_Status'] = 0;
                    if (!array_key_exists('RPM', $gpsInput))  $gpsInput['RPM'] = 0;
                    // $gpsInput->mergeIfMissing(['Drum_Status' => 0]);
                    // $gpsInput->mergeIfMissing(['RPM' => 0]);

                    $transformedData = $isExist->value('vehicle_status') == 1 ? $this->dataTransformation($gpsInput) : null;

                    // Save GPS Data to MongoDB
                    $newGps = Gps::create([
                        'Vendor_Key' => $gpsInput['CompanyKey'],
                        'Timestamp' => $gpsInput['Timestamp'],
                        'GPS' => $gpsInput['GPS'],
                        'Ignition' => $gpsInput['Ignition'],
                        'Latitude' => $gpsInput['Latitude'],
                        'Longitude' => $gpsInput['Longitude'],
                        'Altitude' => $gpsInput['Altitude'],
                        'Speed' => $gpsInput['Speed'],
                        'Course' => $gpsInput['Course'],
                        'Satellite_Count' => $gpsInput['Satellite_Count'],
                        'ADC1' => $gpsInput['ADC1'],
                        'ADC2' => $gpsInput['ADC2'],
                        // 'Drum_Status' => $gpsInput['Drum_Status'],
                        'Drum_Status' => 0,                             // Always ZERO  as of now
                        'Mileage' => $gpsInput['Mileage'],
                        'RPM' => $gpsInput['RPM'] ?? 0,
                        'Device_ID' => $gpsInput['Device_ID'],
                        'Position' => $transformedData
                    ]);

                    // Forward transformed GPS data to Wlocate
                    $socketCtrl = new GPSSocketController();
                    $socketCtrl->submitFormattedGPS($transformedData, $transporter->value('wl_ip'), $transporter->value('wl_port'));

                    if ($newGps)
                        $successList->push($gpsInput);

                    else
                        $response->ErrorResponse('Server Error', 500);
                }

                // If vehicle already exist and with status of unregistered
                else
                    $successList->push($gpsInput);
            } else $failedList->push($gpsInput);
        });

        return $response->ArrayResponse('Status of data submitted', $successList, $failedList);
        // foreach ($request->all() as $gpsInput) {
        //     $transporter->value('id') = Vendor::where('vendor_key', $gpsInput['CompanyKey'])->value('id');

        //     if ($transporter->value('id')) {
        //         $isExist = Vehicle::where('device_id_plate_no', $gpsInput['Device_ID'])->get();

        //         // If vehicle does not exist create vehicle with status unregistered and ignore gps data
        //         if ($isExist->value('id') == null) {
        //             $newVehicle = Vehicle::create([
        //                 'vehicle_status' => 3,
        //                 'device_id_plate_no' => $gpsInput['Device_ID'],
        //                 'transporter_id' => $transporter->value('id'),
        //                 'mileage' => $gpsInput['Mileage']
        //             ]);

        //             if ($newVehicle)
        //                 array_push($successUnregDevice, $gpsInput);
        //                 // return $response->SuccessResponse('Unrecognized vehicle is saved.', $newVehicle);

        //             return $response->ErrorResponse('Server Error', 500);
        //         }

        //         // Save GPS/Position data if vehicle exist and status is not unregistered
        //         else if ($isExist->value('vehicle_status') != 3) {
        //             // Add default value if these are missing in the payload
        //             $gpsInput->mergeIfMissing(['Drum_Status' => 0]);
        //             $gpsInput->mergeIfMissing(['RPM' => 0]);

        //             $transformedData = $isExist->value('vehicle_status') == 1 ? $this->dataTransformation($gpsInput) : null;

        //             // Save GPS Data to MongoDB
        //             $newGps = Gps::create([
        //                 'Vendor_Key' => $gpsInput['CompanyKey'],
        //                 'Timestamp' => $gpsInput['Timestamp'],
        //                 'GPS' => $gpsInput['GPS'],
        //                 'Ignition' => $gpsInput['Ignition'],
        //                 'Latitude' => $gpsInput['Latitude'],
        //                 'Longitude' => $gpsInput['Longitude'],
        //                 'Altitude' => $gpsInput['Altitude'],
        //                 'Speed' => $gpsInput['Speed'],
        //                 'Course' => $gpsInput['Course'],
        //                 'Satellite_Count' => $gpsInput['Satellite_Count'],
        //                 'ADC1' => $gpsInput['ADC1'],
        //                 'ADC2' => $gpsInput['ADC2'],
        //                 // 'Drum_Status' => $gpsInput['Drum_Status'],
        //                 'Drum_Status' => 0,                             // Always ZERO  as of now
        //                 'Mileage' => $gpsInput['Mileage'],
        //                 'RPM' => $gpsInput['RPM'] ?? 0,
        //                 'Device_ID' => $gpsInput['Device_ID'],
        //                 'Position' => $transformedData
        //             ]);

        //             // Forward transformed GPS data to Wlocate
        //             // $socketCtrl = new GPSSocketController();
        //             // $socketCtrl->submitFormattedGPS($transformedData);

        //             if ($newGps)
        //                 array_push($success, $gpsInput);
        //                 // return $response->SuccessResponse('Position is successfully saved.', $gpsInput);

        //             return $response->ErrorResponse('Server Error', 500);
        //         }

        //         // If vehicle already exist and with status of unregistered
        //         else
        //             array_push($existUnregDevice, $gpsInput);
        //             // return $response->ErrorResponse('Unrecognized vehicle already exist!', 409);
        //     }

        //     // return $response->ErrorResponse('Company key/Vendor key does not exist!', 404);
        //     array_push($failed, $gpsInput);
        // }

        // return $response->ArrayResponse('Status of submitted data', $result, $existUnregDevice, $success, $failed);

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
        } else
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

        // return $gpsData . '\r';
        return $gpsData;
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
        // IN0 - from transporter/vendor supplied value

        return $ignition == 0 ? 10 : 11;
    }

    private function validateInput($request)
    {
        // return Validator::make($request->all(), [
        return Validator::make($request, [
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
