<?php

namespace App\Http\Controllers;

use App\Http\Response\ApiResponse;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VehicleController extends Controller
{
    public function create(Request $request)
    {
        $response = new ApiResponse();
        $isVehicleExist = Vehicle::where('device_id_plate_no', $request->device_id_plate_no)->exists();

        if ($isVehicleExist)
            return $response->ErrorResponse('Vehicle already exist!', 409);

        else {
            $newVehicle = Vehicle::create([
                'driver_name' => $request->driver_name,
                'vehicle_status' => $request->vehicle_status,
                // 'contact_no' => $request->contact_no,
                'device_id_plate_no' => $request->device_id_plate_no,
                'vendor_id' => $request->vendor_id,
                'mileage' => $request->mileage,
                'register_by_user_id' => Auth::user()->id
            ]);

            if ($newVehicle) {
                $newVehicleRec = $this->vehicleById($newVehicle->id);

                $responseData = ['vehicle' => $this->hideFields($newVehicleRec)];
                return $response->SuccessResponse('Vehicle is successfully registered', $responseData);
            }

            return $response->ErrorResponse('Server Error', 500);
        }
    }

    public function list(Request $request)
    {
        $vehicleReq = Vehicle::select();
        if ($request->vendor_id)
            $vehicleReq->where('vendor_id', $request->vendor_id);
        if ($request->vehicle_status)
            $vehicleReq->where('vehicle_status', $request->vehicle_status);

        $data = $vehicleReq->with(['vendor', 'register_by', 'updated_by'])->get();
        foreach ($data as $rec) {
            $this->hideFields($rec);
        }

        return $data;
    }

    public function vehicleById($id)
    {
        $vehicle = Vehicle::with(['vendor', 'register_by', 'updated_by'])->find($id);

        if ($vehicle) {
            return $this->hideFields($vehicle);
        }

        $response = new ApiResponse();
        return $response->ErrorResponse('Vehicle not found!', 404);
    }

    public function update($id, Request $request)
    {
        $response = new ApiResponse();

        if ($id == $request->id) {
            $vehicle = Vehicle::find($id);

            if ($vehicle) {
                $vehicle->update([
                    'driver_name' => $request->driver_name,
                    'vehicle_status' => $request->vehicle_status,
                    // 'contact_no' => $request->contact_no,
                    'device_id_plate_no' => $request->device_id_plate_no,
                    'vendor_id' => $request->vendor_id,
                    'mileage' => $request->mileage,
                    'updated_by_user_id' => Auth::user()->id
                ]);

                $vehicleData = $this->vehicleById($vehicle->id);

                return $response->SuccessResponse('Vehicle is successfully updated!', $vehicleData);
            }

            return $response->ErrorResponse('Vehicle not found!', 404);
        }

        return $response->ErrorResponse('Vehicle Id does not matched!', 409);
    }

    public function massUpdate(Request $request)
    {
        $response = new ApiResponse();
        $datas = $request->collect();

        foreach ($datas as $vehicleData) {
            $exist = Vehicle::find($vehicleData['id']);

            if ($exist) 
                $exist->update([
                    'driver_name' => $vehicleData['driver_name'],
                    'vehicle_status' => $vehicleData['vehicle_status'],
                    // 'contact_no' => $vehicleData['contact_no'],
                    'device_id_plate_no' => $vehicleData['device_id_plate_no'],
                    'vendor_id' => $vehicleData['vendor_id'],
                    'mileage' => $vehicleData['mileage'],
                    'updated_by_user_id' => Auth::user()->id
                ]);

            else
                return $response->ErrorResponse('Vehicle id ' . $vehicleData['id'] . ' not found!', 404);
        }

        return $response->SuccessResponse('Vehicle is successfully updated!', []);
    }

    public function delete($id)
    {
        $response = new ApiResponse();
        $vehicle = Vehicle::find($id);

        if ($vehicle) {
            $vehicle->delete();
            return $response->SuccessResponse('Vehicle is successfully deleted!', $vehicle);
        }

        return $response->ErrorResponse('Vehicle does not exist!', 404);
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
}
