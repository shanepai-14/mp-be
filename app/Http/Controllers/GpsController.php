<?php

namespace App\Http\Controllers;

use App\Http\Response\ApiResponse;
use App\Models\Gps;
use App\Models\Vehicle;
use Illuminate\Http\Request;

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

    public function sendGPS(Request $request)
    {
        $response = new ApiResponse();

        $isExist = Vehicle::where('device_id_plate_no', $request->Device_ID)->exists();

        if (!$isExist) {
            $newVehicle = Vehicle::create([
                'vehicle_status' => 3,
                'device_id_plate_no' => $request->Device_ID,
                'mileage' => $request->Mileage
            ]);

            if ($newVehicle)
                return $response->SuccessResponse('Unrecognized vehicle is saved.', $newVehicle);

            return $response->ErrorResponse('Server Error', 500);
        }

        $transformedData = $this->dataTransformation($request->collect());

        $newGps = Gps::create([
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
            'Drum_Status' => $request->Drum_Status,
            'Mileage' => $request->Mileage,
            'RPM' => $request->RPM,
            'Device_ID' => $request->Device_ID,
            'Position' => $transformedData
        ]);

        if ($newGps) {
            return $response->SuccessResponse('Position is successfully saved.', $request->collect());
        }

        return $response->ErrorResponse('Server Error', 500);
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
                    $gpsData .= ',' . 'io_status';
                    break;
                default:
                    $gpsData .= ',' . $data[$patternVal];
                    break;
            }
        }

        return $gpsData . '\r';
    }

}
