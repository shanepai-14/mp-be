<?php

namespace App\Http\Controllers;

use App\Exports\ProvisioningVehiclesExport;
use App\Exports\UnregisteredVehiclesExport;
use App\Exports\VehiclesExport;
use App\Http\Response\ApiResponse;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class VehicleController extends Controller
{
    /**
     * @OA\Post(
     *     tags={"Vehicle"},
     *     path="/vehicle/create",
     *     summary="Create vehicle",
     *     operationId="CreateVehicle",
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         description="Vehicle Information",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                  @OA\Property(
     *                     property="device_id_plate_no",
     *                     type="string"
     *                 ),
     *                  @OA\Property(
     *                     property="transporter_id",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="vehicle_status",
     *                     type="integer"
     *                 ),
     *                 example={"device_id_plate_no": "ATH0001", 
     *                          "transporter_id": 1,"vehicle_status": 2 }
     *             )
     *         )
     *     ),

     *     @OA\Response(
     *         response=200,
     *         description="Vehicle is successfully registered",
     *         @OA\JsonContent(ref="#/components/schemas/Vehicle")
     *     ),
     *     @OA\Response(
     *          response=401,
     *          description="Unauthenticated",
     *      ),
     *     @OA\Response(
     *          response=403,
     *          description="Forbidden",
     *      ),
     *     @OA\Response(
     *          response=409,
     *          description="Vehicle already exist!",
     *      ),
     *     @OA\Response(
     *          response=500,
     *          description="Internal Server Error",
     *      ),
     * )
     */
    public function create(Request $request)
    {
        $response = new ApiResponse();
        $isVehicleExist = Vehicle::where('device_id_plate_no', $request->device_id_plate_no)->exists();

        if ($isVehicleExist)
            return $response->ErrorResponse('Vehicle already exist!', 409);

        else {
            $newVehicle = Vehicle::create([
                'device_id_plate_no' => $request->device_id_plate_no,
                'transporter_id' => $request->transporter_id,
                'vehicle_status' => $request->vehicle_status,
                'register_by_user_id' => Auth::user()->id
                // 'driver_name' => $request->driver_name,
                // 'contact_no' => $request->contact_no,
                // 'mileage' => $request->mileage,
            ]);

            if ($newVehicle) {
                $newVehicleRec = $this->vehicleById($newVehicle->id);

                $responseData = ['vehicle' => $newVehicleRec];
                return $response->SuccessResponse('Vehicle is successfully registered', $responseData);
            }

            return $response->ErrorResponse('Server Error', 500);
        }
    }

    /**
     * @OA\Post(
     *     tags={"Vehicle"},
     *     path="/vehicle/list",
     *     summary="Get list of registered vehicles",
     *     operationId="VehicleList",
     *     security={{"bearerAuth": {}}},
     * @OA\RequestBody(
     *         description="Vehicle Id - NOTE: If vehicle_id object is omitted then all users will be return.",
     *         required=false,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="transporter_id",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="vehicle_status",
     *                     type="integer"
     *                 ),
     *                 example={"transporter_id": 0, "vehicle_status": 0}
     *             )
     *         )
     *     ),
     * @OA\Response(
     *         response=200,
     *         description="ok"
     *     ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthenticated",
     *      ),
     *      @OA\Response(
     *          response=403,
     *          description="Forbidden"
     *      )
     * )
     */
    public function list(Request $request)
    {
        $vehicleReq = Vehicle::select();
        if ($request->transporter_id)
            $vehicleReq->where('transporter_id', $request->transporter_id);
        if ($request->vehicle_status)
            $vehicleReq->where('vehicle_status', $request->vehicle_status);

        $data = $vehicleReq->with(['transporter', 'register_by', 'updated_by'])->get();
        foreach ($data as $rec) {
            $this->hideFields($rec);
        }

        return $data;
    }

    /**
     * @OA\Get(
     *     tags={"Vehicle"},
     *     path="/vehicle/vehicleById/{id}",
     *     summary="Get vehicle by vehicle id",
     *     operationId="GetVehicleById",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         in="path",
     *         name="id",
     *         required=true,
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="ok",
     *         @OA\JsonContent(ref="#/components/schemas/Vehicle")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Vehicle not found"
     *     ),
     * )
     */
    public function vehicleById($id)
    {
        $vehicle = Vehicle::with(['transporter', 'register_by', 'updated_by'])->find($id);

        if ($vehicle) {
            return $this->hideFields($vehicle);
        }

        $response = new ApiResponse();
        return $response->ErrorResponse('Vehicle not found!', 404);
    }

    /**
     * @OA\Put(
     *     tags={"Vehicle"},
     *     path="/vehicle/update/{id}",
     *     summary="Updated Vehicle",
     *     description="Update vehicle information.",
     *     operationId="UpdateVehicle",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         in="path",
     *         name="id",
     *         required=true,
     *         description="id to be updated",
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *    @OA\RequestBody(
     *         description="Updated vehicle object",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="id",
     *                     type="integer"
     *                 ),
     *                  @OA\Property(
     *                     property="device_id_plate_no",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="transporter_id",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="vehicle_status",
     *                     type="integer"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Vehicle is successfully updated!"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Vehicle not found"
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Vehicle Id does not matched!"
     *     )
     * )
     */
    public function update($id, Request $request)
    {
        $response = new ApiResponse();
       
        if ($id == $request->id) {
            $vehicle = Vehicle::find($id);
           
            if ($vehicle) {
                // Forwarding of DEVICE and VEHICLE info to WLOC-MP Integration Server
                // - If vehicle_status == 1 (Approved), check if DEVICE and VEHICLE is already registered in WLOC-MP Integration Server
                if ($request->vehicle_status === 1) {
                    $integration = new IntegrationController($request->device_id_plate_no, $request->mileage, $request->driver_name);

                    // If device and vehicle are successfully uploaded to integration server
                    // update vehicle status to approved in mysql server
                    $uploadResult = $integration->uploading();

                    if ($uploadResult == 200 || $uploadResult == 409)
                        $this->updateInfo($vehicle, $request);

                    else
                        return $response->ErrorResponse('Failed, something went wrong in integration server', 500);
                } else
                    $this->updateInfo($vehicle, $request->collect());

                $vehicleData = $this->vehicleById($vehicle->id);
                return $response->SuccessResponse('Vehicle is successfully updated!', $vehicleData);
            }

            return $response->ErrorResponse('Vehicle not found!', 404);
        }

        return $response->ErrorResponse('Vehicle Id does not matched!', 409);
    }

    private function updateInfo($vehicle, $request)
    { 
        $vehicle->update([
            'device_id_plate_no' => $request['device_id_plate_no'],
            'transporter_id' => $request['transporter_id'],
            'vehicle_status' => $request['vehicle_status'],
            'updated_by_user_id' => Auth::user()->id
            // 'driver_name' => $request['driver_name,
            // 'mileage' => $request['mileage'],
        ]);
    }

    /**
     * @OA\Put(
     *     tags={"Vehicle"},
     *     path="/vehicle/massUpdate",
     *     summary="Updated Vehicles",
     *     description="Mass update vehicle information.",
     *     operationId="MassUpdateVehicle",
     *     security={{"bearerAuth": {}}},
     *    @OA\RequestBody(
     *         description="Updated vehicles array of object",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                  @OA\Property(
     *                     property="id",
     *                     type="integer"
     *                 ),
     *                  @OA\Property(
     *                     property="device_id_plate_no",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="transporter_id",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="vehicle_status",
     *                     type="integer"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Vehicle is successfully updated!"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Vehicle not found"
     *     )
     * )
     */
    public function massUpdate(Request $request)
    {
        $response = new ApiResponse();
        $datas = $request->collect();
        $failed = array();
       
        foreach ($datas as $updateData) {
            $exist = Vehicle::find($updateData['id']);
           
            if ($exist)
            {
                // Forwarding of DEVICE and VEHICLE info to WLOC-MP Integration Server
                // - If vehicle_status == 1 (Approved), check if DEVICE and VEHICLE is already registered in WLOC-MP Integration Server
                if ($updateData['vehicle_status'] === 1) {
                    $integration = new IntegrationController($updateData['device_id_plate_no'], $updateData['mileage'], $updateData['driver_name']);

                    // If device and vehicle are successfully uploaded to integration server
                    // update vehicle status to approved in mysql server
                    $uploadResult = $integration->uploading();

                    if ($uploadResult == 200 || $uploadResult == 409)
                        $this->updateInfo($exist, $updateData);

                    else
                        array_push($failed, $updateData);

                } else
                    $this->updateInfo($exist, $updateData);
            }

            else
                array_push($failed, $updateData);
        }
       
        if(count($failed) == count($datas))
            return $response->ErrorResponse('Failed, No vehicle was updated', 500);

        return $response->SuccessResponse('Vehicle is successfully updated!', $failed);
    }

    /**
     * @OA\Delete(
     *     tags={"Vehicle"},
     *     path="/vehicle/delete/{id}",
     *     summary="Delete vehicle by vehicle id",
     *     operationId="DeleteVehicle",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         in="path",
     *         name="id",
     *         required=true,
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Vehicle is successfully deleted!",
     *         @OA\JsonContent(ref="#/components/schemas/Vehicle")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Vehicle does not exist!"
     *     ),
     * )
     */
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

    public function vehicleExport(Request $request)
    {
        $transporter_id = $request->query('transporter_id');
        $vehicle_status = $request->query('vehicle_status');
        return (new VehiclesExport($transporter_id, $vehicle_status))->download('vehicles.xlsx');
    }

    public function provisioningExport(Request $request)
    {
        $transporter_id = $request->query('transporter_id');
        $vehicle_status = $request->query('vehicle_status');
        return (new ProvisioningVehiclesExport($transporter_id, $vehicle_status))->download('provisioning_vehicles.xlsx');
    }

    public function unregisteredExport(Request $request)
    {
        $transporter_id = $request->query('transporter_id');
        return (new UnregisteredVehiclesExport($transporter_id))->download('unregistered_vehicles.xlsx');
    }

    private function hideFields($vehicle)
    {
        if ($vehicle->transporter)
            $vehicle->transporter->makeHidden(['transporter_address', 'transporter_contact_no', 'transporter_key', 'transporter_email']);

        if ($vehicle->register_by)
            $vehicle->register_by->makeHidden(['username_email', 'transporter_id', 'contact_no', 'user_role', 'email_verified_at', 'first_login']);

        if ($vehicle->updated_by)
            $vehicle->updated_by->makeHidden(['username_email', 'transporter_id', 'contact_no', 'user_role', 'email_verified_at', 'first_login']);

        return $vehicle;
    }
}
