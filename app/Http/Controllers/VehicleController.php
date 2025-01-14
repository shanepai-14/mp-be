<?php

namespace App\Http\Controllers;

use App\Exports\ProvisioningVehiclesExport;
use App\Exports\UnregisteredVehiclesExport;
use App\Exports\VehiclesExport;
use App\Http\Response\ApiResponse;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VehicleController extends Controller
{
    // /**
    //  * @OA\Post(
    //  *     tags={"Vehicle"},
    //  *     path="/vehicle/create",
    //  *     summary="Create vehicle",
    //  *     operationId="CreateVehicle",
    //  *     security={{"bearerAuth": {}}},
    //  *     @OA\RequestBody(
    //  *         description="Vehicle Information",
    //  *         required=true,
    //  *         @OA\MediaType(
    //  *             mediaType="application/json",
    //  *             @OA\Schema(
    //  *                  @OA\Property(
    //  *                     property="device_id_plate_no",
    //  *                     type="string"
    //  *                 ),
    //  *                  @OA\Property(
    //  *                     property="vendor_id",
    //  *                     type="integer"
    //  *                 ),
    //  *                 example={"device_id_plate_no": "ATH0001",
    //  *                          "vendor_id": 1 }
    //  *             )
    //  *         )
    //  *     ),

    //  *     @OA\Response(
    //  *         response=200,
    //  *         description="Vehicle is successfully registered",
    //  *         @OA\JsonContent(ref="#/components/schemas/Vehicle")
    //  *     ),
    //  *     @OA\Response(
    //  *          response=401,
    //  *          description="Unauthenticated",
    //  *      ),
    //  *     @OA\Response(
    //  *          response=403,
    //  *          description="Forbidden",
    //  *      ),
    //  *     @OA\Response(
    //  *          response=409,
    //  *          description="Vehicle already exist!",
    //  *      ),
    //  *     @OA\Response(
    //  *          response=500,
    //  *          description="Internal Server Error",
    //  *      ),
    //  * )
    //  */
    public function create(Request $request)
    {
        $response = new ApiResponse();
        $isVehicleExist = Vehicle::where('device_id_plate_no', $request->device_id_plate_no)->exists();

        if ($isVehicleExist)
            return $response->ErrorResponse('Vehicle already exist!', 409);
        else {
            $newVehicle = Vehicle::create([
                'device_id_plate_no' => $request->device_id_plate_no,
                'transporter_id' => $request->vendor_id,
                // 'vehicle_status' => $request->vehicle_status,
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
     *     path="/vehicle/create-complete-info",
     *     summary="Create vehicle with assignment and customer",
     *     operationId="CreateVehicleAssignmentCustomer",
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         description="Vehicle, Assignment and Customer Information",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                  @OA\Property(
     *                     property="device_id_plate_no",
     *                     type="string"
     *                 ),
     *                  @OA\Property(
     *                     property="vendor_id",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="vehicle_status",
     *                     type="integer"
     *                 ),
     *                  @OA\Property(
     *                     property="driver_name",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="mileage",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="customer_code",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="customers",
     *                     type="array",
     *                     @OA\Items(
     *                      @OA\Property(
     *                          property="customer_id",
     *                          type="integer"
     *                      ),
     *                      @OA\Property(
     *                          property="ipport_id",
     *                          type="integer"
     *                      )
     *                     )
     *                 ),
     *                 example={"device_id_plate_no": "ATH0001",
     *                          "vendor_id": 1, "vehicle_status": 4,
     *                          "driver_name": "Juan Dela Cruz", "mileage": 0, "customer_code": "ICPL, ALNC",
     *                          "customers": {{ "customer_id": 1, "ipport_id": 1 }} }
     *             )
     *         )
     *     ),

     *     @OA\Response(
     *         response=200,
     *         description="Vehicle is successfully registered",
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
    public function createCompleteData(Request $request)
    {
        $response = new ApiResponse();
        $vehicleCreate = $this->create($request);
        $vehicleResponse = (json_decode(json_encode($vehicleCreate), true)['original']);

        try {
            if ($vehicleCreate?->status() == 200) {
                $request['vehicle_id'] = $vehicleResponse['data']['vehicle']['id'];

                $assignment = new VehicleAssignmentsController();
                $assignCreate = $assignment->create($request);
                $assignResponse = (json_decode(json_encode($assignCreate), true)['original']);

                if ($assignCreate?->status() === 200) {
                    $vehicleAssignmentCreated = $assignResponse['data']['vehicle-assignment'];
                    $request['vehicle_assignment_id'] = $vehicleAssignmentCreated['id'];

                    $customerCount = count($request->customers ?? []);
                    $currCustomersCreated = [];
                    if ($customerCount > 0) {
                        $currCust = new CurrentCustomerController();
                        for ($i = 0; $i < $customerCount; $i++) {
                            $currentCustomer = $request->customers[$i];
                            $request['customer_id'] = $currentCustomer['customer_id'];
                            $request['ipport_id'] = $currentCustomer['ipport_id'];
                            $currCustCreate = $currCust->create($request);
                            $currCustResponse = (json_decode(json_encode($currCustCreate), true)['original']);

                            if ($currCustCreate?->status() == 200) {
                                array_push($currCustomersCreated, $currCustResponse['data']['current-customer']);
                            } else {
                                $this->forceDelete($request['vehicle_id']);
                                return $response->ErrorResponse($currCustResponse['message'] ?? 'Failed to create current customer', $vehicleCreate?->status());
                            }
                        }

                        //Update customer code based on the current customer records
                        $vehicleAssignmentCreated = VehicleAssignment::find($request['vehicle_assignment_id']);
                        $vehicleAssignmentCreated['customer_code'] = join(", ", array_unique(array_map(function ($value) {
                            return $value['customer']['customer_code'];
                        }, $currCustomersCreated)));
                        $vehicleAssignmentCreated->save();

                        //Upload the vehicle and device when it's created by the operator
                        if ($request->vehicle_status === 1) {
                            $request['id'] = $request['vehicle_assignment_id'];
                            $vehicleAssignCtrl = new VehicleAssignmentsController();
                            $uploadVehicleReq = $vehicleAssignCtrl->update($request['vehicle_assignment_id'], $request);
                            $uploadVehicleRes = (json_decode(json_encode($uploadVehicleReq), true)['original']);
                            $reqStatus = $uploadVehicleReq?->status();
                            if ($reqStatus !== 200) {
                                $this->forceDelete($request['vehicle_id']);
                                return $response->ErrorResponse($uploadVehicleRes['message'] ?? 'Create vehicle - something went wrong in integration server', $reqStatus);
                            }
                        }
                    }

                    return $response->SuccessResponse('Vehicle successfully created!', [
                        'vehicle' => $vehicleResponse['data']['vehicle'],
                        'vehicle_assignment' => $vehicleAssignmentCreated,
                        'current_customer' => $currCustomersCreated
                    ]);
                } else {
                    $this->forceDelete($request['vehicle_id']);
                    return $response->ErrorResponse($assignResponse['message'] ?? 'Failed to assign vehicle', $assignCreate?->status());
                }
            }
        } catch (\Throwable $th) {
            $this->forceDelete($request['vehicle_id']);
            throw $th;
        }

        return $response->ErrorResponse($vehicleResponse['message'] ?? 'Failed to create vehicle', $vehicleCreate?->status());
    }

    /**
     * @OA\Post(
     *     tags={"Vehicle"},
     *     path="/vehicle/list",
     *     summary="Get list of registered vehicles",
     *     operationId="VehicleList",
     *     security={{"bearerAuth": {}}},
     * @OA\RequestBody(
     *         description="Vendor Id - NOTE: If vendor_id object is omitted then all users will be return.(For Admin Use Only)",
     *         required=false,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="vendor_id",
     *                     type="integer"
     *                 ),
     *                 example={"vendor_id": 0}
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
        $currUser = Auth::user();
        if($currUser->user_role === 1){
            if ($request->vendor_id)
                $vehicleReq->where('transporter_id', $request->vendor_id);
            // if ($request->vehicle_status)
            //     $vehicleReq->where('vehicle_status', $request->vehicle_status);
        }else if(isset($currUser->transporter_id)){
            $vehicleReq->where('transporter_id', $currUser->transporter_id);
        }else{
            $response = new ApiResponse();
            return $response->ErrorResponse('Vendor Id does not matched!', 409);
        }
        
        $data = $vehicleReq->with(['vendor', 'register_by', 'updated_by'])->get();
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
        $vehicle = Vehicle::with(['vendor', 'register_by', 'updated_by'])->find($id);

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
     *                     property="vendor_id",
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
            'transporter_id' => $request['vendor_id'],
            // 'vehicle_status' => $request['vehicle_status'],
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
     *                     property="vendor_id",
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

            if ($exist) {
                // // Forwarding of DEVICE and VEHICLE info to WLOC-MP Integration Server
                // // - If vehicle_status == 1 (Approved), check if DEVICE and VEHICLE is already registered in WLOC-MP Integration Server
                // if ($updateData['vehicle_status'] === 1) {
                //     $integration = new IntegrationController($updateData['device_id_plate_no'], $updateData['mileage'], $updateData['driver_name']);

                //     // If device and vehicle are successfully uploaded to integration server
                //     // update vehicle status to approved in mysql server
                //     $uploadResult = $integration->uploading();

                //     if ($uploadResult == 200 || $uploadResult == 409)
                //         $this->updateInfo($exist, $updateData);

                //     else
                //         array_push($failed, $updateData);

                // } else
                $this->updateInfo($exist, $updateData);
            } else
                array_push($failed, $updateData);
        }

        if (count($failed) == count($datas))
            return $response->ErrorResponse('Failed, No vehicle was updated', 500);

        return $response->SuccessResponse('Vehicle is successfully updated!', $failed);
    }

    // /**
    //  * @OA\Delete(
    //  *     tags={"Vehicle"},
    //  *     path="/vehicle/delete/{id}",
    //  *     summary="Delete vehicle by vehicle id",
    //  *     operationId="DeleteVehicle",
    //  *     security={{"bearerAuth": {}}},
    //  *     @OA\Parameter(
    //  *         in="path",
    //  *         name="id",
    //  *         required=true,
    //  *         @OA\Schema(
    //  *             type="integer"
    //  *         )
    //  *     ),
    //  *     @OA\Response(
    //  *         response=200,
    //  *         description="Vehicle is successfully deleted!",
    //  *         @OA\JsonContent(ref="#/components/schemas/Vehicle")
    //  *     ),
    //  *     @OA\Response(
    //  *         response=404,
    //  *         description="Vehicle does not exist!"
    //  *     ),
    //  * )
    //  */
    // public function delete($id)
    // {
    //     $response = new ApiResponse();
    //     $vehicle = Vehicle::find($id);

    //     if ($vehicle) {
    //         $vehicle->delete();
    //         return $response->SuccessResponse('Vehicle is successfully deleted!', $vehicle);
    //     }

    //     return $response->ErrorResponse('Vehicle does not exist!', 404);
    // }

    public function vehicleExport(Request $request)
    {
        $vendor_id = $request->vendor_id;
        $vehicle_status = $request->vehicle_status;
        return (new VehiclesExport($vendor_id, $vehicle_status))->download('vehicles.xlsx');
    }

    public function provisioningExport(Request $request)
    {
        $vendor_id = $request->vendor_id;
        $vehicle_status = $request->vehicle_status ?? 4;
        return (new ProvisioningVehiclesExport($vendor_id, $vehicle_status))->download('provisioning_vehicles.xlsx');
    }

    public function unregisteredExport(Request $request)
    {
        $vendor_id = $request->vendor_id;
        return (new UnregisteredVehiclesExport($vendor_id))->download('unregistered_vehicles.xlsx');
    }

    private function hideFields($vehicle)
    {
        if ($vehicle->transporter)
            $vehicle->transporter->makeHidden(['transporter_address', 'transporter_contact_no', 'transporter_key', 'transporter_email']);

        if ($vehicle->vendor)
            $vehicle->vendor->makeHidden(['vendor_address', 'vendor_contact_no', 'vendor_key', 'vendor_email']);

        if ($vehicle->register_by)
            $vehicle->register_by->makeHidden(['username_email', 'transporter_id', 'contact_no', 'user_role', 'email_verified_at', 'first_login']);

        if ($vehicle->updated_by)
            $vehicle->updated_by->makeHidden(['username_email', 'transporter_id', 'contact_no', 'user_role', 'email_verified_at', 'first_login']);

        return $vehicle;
    }

    private function forceDelete($id)
    {
        $vehicle = Vehicle::find($id);
        $vehicle->forceDelete();
    }
}
