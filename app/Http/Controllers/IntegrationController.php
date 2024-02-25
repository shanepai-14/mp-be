<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;

class IntegrationController extends Controller
{
    protected $integrationURI = 'https://findplus.w-locate.com/integration';
    protected $token = null;
    protected $deviceID_plateNum = null;
    protected $mileAge = null;
    protected $driverName = null;

    public function __construct($deviceID_plateNum, $mileAge, $driverName)
    {
        $this->deviceID_plateNum = $deviceID_plateNum;
        $this->mileAge = $mileAge;
        $this->driverName = $driverName;
    }

    // Check if token is already exist and stored in Storage/App/IntegrationToken.txt
    public function uploading()
    {
        // Check Storage/App if IntegrationToken already exist
        if (Storage::disk('local')->exists('IntegrationToken.txt')) {
            $verification = $this->verifyToken();
           
            if ($verification)
            { 
                if($this->checkDevice()) {
                    // If Device already exist in integration server
                    // check vehicle if also exist
                    if($this->checkVehicle())
                        return 409;             // Vehicle already exist in integration server!

                    else {
                        $newVehicleUpload = $this->submitVehicle();

                        dd($newVehicleUpload);

                        // Vehicle is successfully uploaded to integration server!
                        if($newVehicleUpload['Status'] === 'Success')
                            return 200;   
                            
                        return 500;
                    }
                }

                else {
                    $newDeviceUpload = $this->submitDevice();

                    dd($newDeviceUpload);
                    // Device is successfully uploaded to integration server!
                    if($newDeviceUpload['Status'] === 'Success') {
                        $newVehicleUpload = $this->submitVehicle();

                        dd($newVehicleUpload);

                        // Vehicle is successfully uploaded to integration server!
                        if($newVehicleUpload['Status'] === 'Success')
                            return 200;   
                            
                        return 500;
                    }
                
                    return 500;              // Something went wrong when uploading to integration server!
                }
            }

            else {
                $newLogin = $this->login();
                if($newLogin)
                    return $this->uploading();
            }
        } 
        
        else {
            $newLogin = $this->login();
            if($newLogin)
                return $this->uploading();
        }
    }

    // Check if device already exist in the Device List
    public function checkDevice()
    {
        $response = Http::withHeaders([
            'Token' => $this->token
        ])->get($this->integrationURI . '/Device/' . $this->deviceID_plateNum);
        
        return $response->throw()->json();
    }

    // Check if vehicle already exist in the Device List
    public function checkVehicle()
    {
        $response = Http::withHeaders([
            'Token' => $this->token
        ])->get($this->integrationURI . '/Vehicle/' . $this->deviceID_plateNum);
        
        return $response->throw()->json();
    }

    public function login()
    {
        $response = Http::post($this->integrationURI . '/Account/Authenticate', ['Username' => 'athena.my', 'Password' => 'Athena2022']);

        if ($response)
            Storage::disk('local')->put('IntegrationToken.txt', $response['ApiKey']);

        return $response->throw()->json();
    }

    public function verifyToken()
    {
        $this->token = Storage::get('IntegrationToken.txt');

        $response = Http::get($this->integrationURI . '/Account?Key=' . $this->token)->json();
        return $response;
    }

    public function submitDevice() 
    {
        $response = Http::withHeaders([
            'Token' => $this->token
        ])->put($this->integrationURI . '/Device', [
            "DeviceName" => $this->deviceID_plateNum,
            "CompanyName" => "W-locate Pte Ltd",
            "Status" => 1,
            "ServerIP" => "20.195.56.146",
            "ServerPort" => 2199,
            "Protocol" => 0,
            "Division" => "*",
            "Group" => "*",
            "IntervalOn" => 1,
            "IntervalOff" => 10,
            "DeviceType" => "K3G",
            "DevicePhone" => ""
        ])->json();
      
        return $response;
    }
    
    public function submitVehicle() 
    {
        $response = Http::withHeaders([
            'Token' => $this->token
        ])->put($this->integrationURI . '/Vehicle', [
            "VehicleID" => 0,
            "VehicleName" => $this->deviceID_plateNum,
            "DeviceName" => $this->deviceID_plateNum,
            "Category" => "Concrete Mixer",
            "CompanyName" => "W-locate Pte Ltd",
            "Status" => 1,
            "Mileage" => $this->mileAge,
            "Driver" => $this->driverName,
            "Division" => "Software",
            "Group" => "Athena",
            "Remarks" => "Testing vehicle",
            "SpeedLimit" => 0,
            "IdlingLimit" => 0,
            "FuelCapacity" => 0
        ])->json();
      
        return $response;
    }
}
