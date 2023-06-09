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

        // $gps = Gps::with(['vehicle'])->get();
        // return $gps;

        // Sample data
        // "gps_data": "$110731093059,2,1.304431,103.834510,23,68,108,8,12.8,3.68,18,180699,12,K3G0001\r"
        // $gps_data = $request->gps_data;
        // $result = $this->formatGpsData($gps_data);

        $isExist = Vehicle::where('device_id_plate_no', $request->device_ID)->exists();

        if (!$isExist) {
            $newVehicle = Vehicle::create([
                'driver_name' => $request->Driver_Name ?? null,
                'vehicle_status' => 3,
                'device_id_plate_no' => $request->Device_ID,
                'vendor_id' => $request->vendor_id ?? null,
                'mileage' => $request->Mileage
            ]);

            if ($newVehicle) 
                return $response->SuccessResponse('Unrecognized vehicle is saved.', $newVehicle);

            return $response->ErrorResponse('Server Error', 500);
        }

        $newGps = Gps::create([
            'timestamp' => $request->timestamp,
            'gps' => $request->gps,
            'ignition' => $request->ignition,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'altitude' => $request->altitude,
            'speed' => $request->speed,
            'course' => $request->course,
            'satellite_count' => $request->satellite_count,
            'adc1' => $request->adc1,
            'adc2' => $request->adc2,
            'drum_status' => $request->drum_status,
            'mileage' => $request->mileage,
            'rpm' => $request->rpm,
            'device_id' => $request->device_ID
        ]);

        if ($newGps) {
            return $response->SuccessResponse('Position is successfully saved.', $newGps);
        }
        
        return $response->ErrorResponse('Server Error', 500);
    }

    private function hideFields($vehicle)
    {
        $vehicle->vendor->makeHidden(['vendor_address', 'vendor_contact_no', 'vendor_key', 'vendor_email']);
        $vehicle->register_by->makeHidden(['username_email', 'vendor_id', 'contact_no', 'user_role', 'email_verified_at', 'first_login']);

        if ($vehicle->updated_by)
            $vehicle->updated_by->makeHidden(['username_email', 'vendor_id', 'contact_no', 'user_role', 'email_verified_at', 'first_login']);

        else
            $vehicle->updated_at = null;

        return $vehicle;
    }

    // private function formatGpsData($gps_data) {
    //     $arrData = (explode(",", $gps_data));

    //     $data = new Gps();
    //     $data['message_id'] = substr($arrData[0], 0, 1);
    //     $data['timestamp'] = substr($arrData[0], 1, strlen($arrData[0]));
    //     $data['gps'] = $arrData[1];
    //     $data['latitude'] = $arrData[2];
    //     $data['longitude'] = $arrData[3];
    //     $data['altitude'] = $arrData[4];
    //     $data['speed'] = $arrData[5];
    //     $data['course'] = $arrData[6];
    //     $data['satellite_count'] = $arrData[7];
    //     $data['adc1'] = $arrData[8];
    //     $data['adc2'] = $arrData[9];
    //     $data['io_status'] = $arrData[10];
    //     $data['mileage'] = $arrData[11];
    //     $data['rpm'] = $arrData[12];
    //     $data['device_id'] = $arrData[13];

    //     return $data;
    // }
}
