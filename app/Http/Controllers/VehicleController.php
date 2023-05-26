<?php

namespace App\Http\Controllers;

use App\Http\Response\ApiResponse;
use App\Models\Vehicle;
use Illuminate\Http\Request;

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
                'contact_no' => $request->contact_no,
                'device_id_plate_no' => $request->device_id_plate_no,
                'vendor_id' => $request->vendor_id,
                'mileage' => $request->mileage
            ]);

            if ($newVehicle) {
                $responseData = ['vehicle' => $newVehicle];
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

        return $vehicleReq->get();
    }

    public function vehicleById($id)
    {
        $vehicle = Vehicle::find($id);

        if($vehicle) return $vehicle;

        $response = new ApiResponse();
        return $response->ErrorResponse('Vehicle not found!', 404);
    }

    public function update($id, Request $request)
    {
        $response = new ApiResponse();

        if ($id == $request->id) {
            $vehicle = Vehicle::find($id);

            if($vehicle)
            {
                $vehicle->update($request->all());
                return $response->SuccessResponse('Vehicle is successfully updated!', $vehicle);
            }

            return $response->ErrorResponse('Vehicle not found!', 404);
        }

        return $response->ErrorResponse('Vehicle Id does not matched!', 409);
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
}

