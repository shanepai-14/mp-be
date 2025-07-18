<?php

namespace App\Http\Controllers;

use App\Http\Response\ApiResponse;
use App\Models\Customer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class IntegrationController extends Controller
{
    public static $integrationURI = 'https://findplus.w-locate.com:8443/integration';
    protected $token = null;
    protected $customer = null;
    protected $vehicleAssignment = null;
    protected $vehicle = null;
    protected $gateway = null;
    private $loginRetries = 0;

    public function __construct($customer, $vehicle, $vehicleAssignment, $gateway)
    {
        $this->customer = $customer;
        $this->vehicle = $vehicle;
        $this->vehicleAssignment = $vehicleAssignment;
        $this->gateway = $gateway;
        $this->token = $customer['customer_api_key'];
    }

    // Check if token is already exist and stored in Storage/App/IntegrationToken.txt
    public function uploading()
    {
        $response = new ApiResponse();
        return $response->SuccessResponse('', 200);
        // Removed submitting vehicle and device data to findplus
        // if ($this->loginRetries < 3) {
        //     $valid = $this->customer['customer_api_key'] ? $this->verifyToken() : $this->login();

        //     if ($valid) {
        //         $newVehicleUpload = $this->submitVehicle();
        //         if ($newVehicleUpload && $newVehicleUpload['is_success']) {
        //             $is_device_exists = $newVehicleUpload['data'] && array_key_exists('DeviceID', $newVehicleUpload['data']) && $newVehicleUpload['data']['DeviceID'] > 0;
        //             $newDeviceUpload = $this->submitDevice($is_device_exists);
        //             if ($newDeviceUpload && $newDeviceUpload['is_success']) {
        //                 return $response->SuccessResponse('', 200);
        //             }

        //             return $response->ErrorResponse(array_key_exists('message', $newDeviceUpload) ? $newDeviceUpload['message'] : 'Upload Device - something went wrong in integration server!', 500);
        //         }

        //         return $response->ErrorResponse(array_key_exists('message', $newVehicleUpload) ? $newVehicleUpload['message'] : 'Upload Vehicle - something went wrong in integration server', 500);
        //     } else {
        //         $this->loginRetries++;
        //         return $this->uploading();
        //     }
        // } else return $response->ErrorResponse('Failed to login to the account of the customer', 500);
    }

    // Check if device already exist in the Device List
//    public function checkDevice()
//    {
//        $response = Http::withHeaders([
//            'Token' => $this->token
//        ])->get($this::$integrationURI . '/Device/' . $this->vehicle['device_id_plate_no']);
//
//
//        return $response->throw()->json();
//    }

    // Check if vehicle already exist in the Device List
//    public function checkVehicle()
//    {
//        $response = Http::withHeaders([
//            'Token' => $this->token
//        ])->get($this::$integrationURI . '/Vehicle/' . $this->vehicle['device_id_plate_no']);
//
//        info($response->json());
//        return $response->throw()->json();
//    }

    public function login()
    {
        $response = Http::post($this::$integrationURI . '/Account/Authenticate', ['Username' => $this->customer['customer_username'], 'Password' => $this->customer['customer_password']]);
        $responseData = $response->json();

        if ($response->status() === 200) {
            $this->token = $responseData['ApiKey'];
            $this->customer['customer_api_key'] = Crypt::encryptString($this->token);
            return true;
        }

        return false;
    }

    public static function validateAccount($username, $password, $apiKey)
    {
        try {
            if ($apiKey) {
                $response = Http::withHeaders([
                    'Token' => $apiKey
                ])->get(IntegrationController::$integrationURI . '/Account');
                return $response->status() === 200;
            }
            else if ($username && $password) {
                $response = Http::post(IntegrationController::$integrationURI . '/Account/Authenticate', ['Username' => $username, 'Password' => $password]);
                return $response->status() === 200;
            }

            return false;
        } catch (\Throwable $th) {
            info($th);
            return false;
        }
    }

    public function verifyToken()
    {
        $response = Http::withHeaders([
            "Token" => $this->token
        ])->get($this::$integrationURI . '/Account');

        return $response->status() === 200;
    }

    public function hasLoginCredentials()
    {
        return $this->customer['customer_api_key'] || ($this->customer['customer_username'] && $this->customer['customer_password']);
    }

//    public function getDevice()
//    {
//        $response = Http::withHeaders([
//            'Token' => $this->token
//        ])->get($this::$integrationURI . '/Device/' . $this->vehicle['device_id_plate_no']);
//
//        return [
//            'is_success' => $response->status() === 200 || $response->status() === 204,
//            'no_content' => $response->status() === 204,
//            'data' => $response->json()
//        ];
//    }

    public function getVehicle()
    {
        $response = Http::withHeaders([
            'Token' => $this->token
        ])->get($this::$integrationURI . '/Vehicle/' . $this->vehicle['device_id_plate_no']);

        return [
            'is_success' => $response->status() === 200,
            'data' => $response->json()
        ];
    }

    public function submitDevice($is_device_exists)
    {
        $response = [
            'is_success' => true,
            'message' => ''
        ];

        if (!$is_device_exists) {
            //Create new device
            $response_data = Http::withHeaders([
                'Token' => $this->token
            ])->put($this::$integrationURI . '/Device', [
                "DeviceID" => 0,
                "DeviceName" => $this->vehicle['device_id_plate_no'],
                "CompanyName" => $this->customer['customer_name'],
                "Status" => 1,
                "ServerIP" => $this->gateway['ip'],
                "ServerPort" => $this->gateway['port'],
                "Protocol" => 0,
                "Division" => "*",
                "Group" => "*",
                "IntervalOn" => 1,
                "IntervalOff" => 10,
                "DeviceType" => "K3G",
                "DevicePhone" => ""
            ])->json();

            $response['is_success'] = $response_data['Status'] === 'Success';
            if (!$response['is_success']) {
                $response['message'] = array_key_exists('Message', $response_data) ? $response_data['Message'] : 'Upload Device - something went wrong in integration server';
            }
        }

        return $response;
    }

    public function submitVehicle()
    {
        $response = [
            'is_success' => true,
            'message' => '',
            'data' => []
        ];

        $vehicleRes = $this->getVehicle();

        if ($vehicleRes['is_success']) {
            if (!array_key_exists('VehicleID', $vehicleRes['data'] ?? [])) {
                //Create new vehicle
                $response_data = Http::withHeaders([
                    'Token' => $this->token
                ])->put($this::$integrationURI . '/Vehicle', [
                    "VehicleID" => 0,
                    "VehicleName" => $this->vehicle['device_id_plate_no'],
                    "DeviceName" => $this->vehicle['device_id_plate_no'],
                    "Category" => "Concrete Mixer",
                    "CompanyName" => $this->customer['customer_name'],
                    "Status" => 1,
                    "Mileage" => $this->vehicleAssignment['mileage'] ?? 0,
                    // "Driver" => $this->vehicleAssignment['driver_name'] ?? '',
                    "Division" => "*",
                    "Group" => "*",
                    "Remarks" => "",
                    "SpeedLimit" => 0,
                    "IdlingLimit" => 0,
                    "FuelCapacity" => 0
                ])->json();

                $response['is_success'] = $response_data['Status'] === 'Success';
                if (!$response['is_success']) {
                    $response['message'] = array_key_exists('Message', $response_data) ? $response_data['Message'] : 'Upload Vehicle - something went wrong in integration server';
                }
                else {
                    $response['data'] = $response_data;
                }
            }
            else {
                $response['data'] = $vehicleRes['data'];
            }
        }
        else {
            $response_data = $response['data'];
            $response['is_success'] = false;
            $response['message'] = array_key_exists('Message', $response_data) ? $response_data['Message'] : 'Get Vehicle - something went wrong in integration server';
        }

        return $response;
    }

//    public function submitDevice()
//    {
//        $response = null;
//        $deviceRes = $this->getDevice();
//
//        if ($deviceRes['is_success']) {
//            $device = $deviceRes['data'];
//            if ($device) {
//                info('Update device');
//                //Update the device if it's already recorded
//                $response = Http::withHeaders([
//                    'Token' => $this->token
//                ])->put($this::$integrationURI . '/Device', [
//                    "DeviceID" => $device['DeviceID'],
//                    "DeviceName" => $this->vehicle['device_id_plate_no'],
//                    "CompanyName" => $this->customer['customer_name'],
//                    "Status" =>array_key_exists('StatusID', $device) ? $device['StatusID'] : 1,
//                    "ServerIP" => $this->gateway['ip'],
//                    "ServerPort" => $this->gateway['port'],
//                    "Protocol" => array_key_exists('Protocol', $device) ? $device['Protocol'] : 0,
//                    "Division" => array_key_exists('Division', $device) ? $device['Division'] : "*",
//                    "Group" => array_key_exists('Group', $device) ? $device['Group'] : "*",
//                    "IntervalOn" => array_key_exists('IntervalOn', $device) ? $device['IntervalOn'] : 1,
//                    "IntervalOff" => array_key_exists('IntervalOff', $device) ? $device['IntervalOff'] : 10,
//                    "DeviceType" => array_key_exists('IntervalOff', $device) ? $device['IntervalOff'] : "K3G",
//                    "DevicePhone" => ""
//                ])->json();
//            } else {
//                //Create new device
//                $response = Http::withHeaders([
//                    'Token' => $this->token
//                ])->put($this::$integrationURI . '/Device', [
//                    "DeviceID" => 0,
//                    "DeviceName" => $this->vehicle['device_id_plate_no'],
//                    "CompanyName" => $this->customer['customer_name'],
//                    "Status" => 1,
//                    "ServerIP" => $this->gateway['ip'],
//                    "ServerPort" => $this->gateway['port'],
//                    "Protocol" => 0,
//                    "Division" => "*",
//                    "Group" => "*",
//                    "IntervalOn" => 1,
//                    "IntervalOff" => 10,
//                    "DeviceType" => "K3G",
//                    "DevicePhone" => ""
//                ])->json();
//            }
//        }
//
//        return $response;
//    }

//    public function submitVehicle()
//    {
//        $response = null;
//        $vehicleRes = $this->getVehicle();
//
//        if ($vehicleRes['is_success']) {
//            $vehicle = $vehicleRes['data'];
//            if (array_key_exists('VehicleID', $vehicle ?? [])) {
//                //Update the vehicle if it's already recorded
//                $response = Http::withHeaders([
//                    'Token' => $this->token
//                ])->put($this::$integrationURI . '/Vehicle', [
//                    "VehicleID" => array_key_exists('VehicleID', $vehicle) ? $vehicle['VehicleID'] : 0,
//                    "VehicleName" => $this->vehicle['device_id_plate_no'],
//                    "DeviceName" => $this->vehicle['device_id_plate_no'],
//                    "Category" => array_key_exists('Category', $vehicle) ? $vehicle['Category'] : "Concrete Mixer",
//                    "CompanyName" => $this->customer['customer_name'],
//                    "Status" => 1,
//                    "Mileage" => $this->vehicleAssignment['mileage'] ?? 0,
//                    "Driver" => $this->vehicleAssignment['driver_name'] ?? '',
//                    "Division" => array_key_exists('Division', $vehicle) ? $vehicle['Division'] : "Software",
//                    "Group" => array_key_exists('Group', $vehicle) ? $vehicle['Group'] : "Athena",
//                    "Remarks" => array_key_exists('Remarks', $vehicle) ? $vehicle['Remarks'] : "Testing vehicle",
//                    "SpeedLimit" => array_key_exists('SpeedLimit', $vehicle) ? $vehicle['SpeedLimit'] : 0,
//                    "IdlingLimit" => array_key_exists('IdlingLimit', $vehicle) ? $vehicle['IdlingLimit'] : 0,
//                    "FuelCapacity" => array_key_exists('FuelCapacity', $vehicle) ? $vehicle['FuelCapacity'] : 0
//                ])->json();
//            } else {
//                //Create new vehicle
//                $response = Http::withHeaders([
//                    'Token' => $this->token
//                ])->put($this::$integrationURI . '/Vehicle', [
//                    "VehicleID" => 0,
//                    "VehicleName" => $this->vehicle['device_id_plate_no'],
//                    "DeviceName" => $this->vehicle['device_id_plate_no'],
//                    "Category" => "Concrete Mixer",
//                    "CompanyName" => $this->customer['customer_name'],
//                    "Status" => 1,
//                    "Mileage" => $this->vehicleAssignment['mileage'] ?? 0,
//                    "Driver" => $this->vehicleAssignment['driver_name'] ?? '',
//                    "Division" => "*",
//                    "Group" => "*",
//                    "Remarks" => "",
//                    "SpeedLimit" => 0,
//                    "IdlingLimit" => 0,
//                    "FuelCapacity" => 0
//                ])->json();
//            }
//        }
//
//        return $response;
//    }
}
