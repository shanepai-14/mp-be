<?php

namespace App\Console\Commands;

use App\Http\Controllers\GPSSocketController;
use App\Models\CurrentCustomer;
use App\Models\Gps;
use App\Models\Vehicle;
use App\Models\Transporter;
use App\Models\VehicleAssignment;
use Illuminate\Console\Command;

class ReceiveGPS extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:receivegps {gps}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Receive GPS raw data from transporter devices';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $data = $this->argument(('gps'));

        $this->convertData($data);
        return Command::SUCCESS;
    }

    private function convertData($rawData)
    {
        //remove leading and trailing white space and $ sign in the start
        $cleanRawData = ltrim(trim($rawData), '$');

        $arrRawData = explode(",", $cleanRawData);
        $gpsData = array();
        $gpsData['CompanyKey'] = $arrRawData[0];
        $gpsData['Timestamp'] = $arrRawData[1];
        $gpsData['GPS'] = $arrRawData[2];
        $gpsData['Ignition'] = $arrRawData[3];
        $gpsData['Latitude'] = $arrRawData[4];
        $gpsData['Longitude'] = $arrRawData[5];
        $gpsData['Altitude'] = $arrRawData[6];
        $gpsData['Speed'] = $arrRawData[7];
        $gpsData['Course'] = $arrRawData[8];
        $gpsData['Satellite_Count'] = $arrRawData[9];
        $gpsData['ADC1'] = $arrRawData[10];
        $gpsData['ADC2'] = $arrRawData[11];
        $gpsData['Mileage'] = $arrRawData[12];
        $gpsData['Drum_Status'] = $arrRawData[13];
        $gpsData['RPM'] = $arrRawData[14];
        $gpsData['Vehicle_ID'] = $arrRawData[15];

        // $sendData = new GpsController();
        // $sendData->sendGPS($gpsData);
        $this->sendGPS($gpsData);
    }

    private function sendGPS($data)
    {
        // $transporter_id = Vendor::where('transporter_key', $data['CompanyKey'])->value('id');
        $transporter = Transporter::where('transporter_key', $data['CompanyKey'])->first();

        if ($transporter) {
            $vehicle = Vehicle::where('device_id_plate_no', $data['Vehicle_ID'])->first();

            // If vehicle does not exist create vehicle with status unregistered and ignore gps data
            if (!$vehicle) {
                $newVehicle = Vehicle::create([
                    // 'vehicle_status' => 3,
                    'device_id_plate_no' => $data['Vehicle_ID'],
                    'transporter_id' => $transporter->id,
                    // 'mileage' => array_key_exists('Mileage', $data) ? $data['Mileage'] : 0
                ]);

                $newVehicleAssignment = VehicleAssignment::create([
                    'vehicle_id' => $newVehicle->id,
                    'mileage' => array_key_exists('Mileage', $data) ? $data['Mileage'] : 0,
                    'vehicle_status' => 3
                ]);

                if ($newVehicle && $newVehicleAssignment)
                    info('Unrecognized vehicle is saved. ' . $data['CompanyKey']);

                else
                    info('Server Error ' . $data['CompanyKey']);
            }

            // Save GPS/Position data if vehicle exist and status is NOT unregistered
            else {
                $vehicleAssignment = VehicleAssignment::where('vehicle_id', $vehicle->id)->latest('id')->first();
                if ($vehicleAssignment->vehicle_status != 3) {
                    if (!array_key_exists('Drum_Status', $data))  $data['Drum_Status'] = 0;
                    if (!array_key_exists('RPM', $data))  $data['RPM'] = 0;

                    $transformedData = $vehicleAssignment->vehicle_status == 1 ? $this->dataTransformation($data) : null;

                    // Save GPS Data to MongoDB
                    $newGps = Gps::create([
                        'Vendor_Key' => $data['CompanyKey'],
                        'Timestamp' => $data['Timestamp'],
                        'GPS' => $data['GPS'],
                        'Ignition' => $data['Ignition'],
                        'Latitude' => $data['Latitude'],
                        'Longitude' => $data['Longitude'],
                        'Altitude' => $data['Altitude'],
                        'Speed' => $data['Speed'],
                        'Course' => $data['Course'],
                        'Satellite_Count' => $data['Satellite_Count'],
                        'ADC1' => $data['ADC1'],
                        'ADC2' => $data['ADC2'],
                        // 'Drum_Status' => $data->Drum_Status,
                        'Drum_Status' => $data['Drum_Status'],                             // Always ZERO  as of now
                        'Mileage' => $data['Mileage'],
                        'RPM' => $data['RPM'],
                        'Vehicle_ID' => $data['Vehicle_ID'],
                        'Position' => $transformedData
                    ]);

                    if ($vehicleAssignment->vehicle_status == 1) {
                        //Get IP port
                        $currentCustomer = CurrentCustomer::with(['ipport'])->where('vehicle_assignment_id', $vehicleAssignment->id)->first();
                        $ipport = $currentCustomer->ipport;
                        // Forward transformed GPS data to Wlocate
                        $socketCtrl = new GPSSocketController();
                        $socketCtrl->submitFormattedGPS($transformedData, $ipport->ip, $ipport->port);
                    }

                    if ($newGps)
                        info('Position is successfully saved.');

                    else
                        info('Internal Server Error');
                }

                // // If vehicle already exist and with status of unregistered
                else
                    info('Vehicle is not registered');
            }
        } else info('Vendor key not found');
    }

    private function dataTransformation($data)
    {
        $gpsData = '$';
        $pattern = ['Timestamp', 'GPS', 'Latitude', 'Longitude', 'Altitude', 'Speed', 'Course', 'Satellite_Count', 'ADC1', 'ADC2', 'IO', 'Mileage', 'RPM', 'Vehicle_ID'];

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

    private function ioStatusCalculation($ignition)
    {
        // X - 0
        // OUT1 - always Low (0)
        // OUT0 - always Low (0)
        // IN4 - always high (1)
        // IN3 - drum direction is always (0)
        // IN2 - always low (0)
        // IN1 - always Low (0)
        // IN0 - from vendor/transporter supplied value

        return $ignition == 0 ? 10 : 11;
    }
}
