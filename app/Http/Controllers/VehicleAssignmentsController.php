<?php

namespace App\Http\Controllers;

use App\Http\Response\ApiResponse;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VehicleAssignmentsController extends Controller
{
    /**
     * @OA\Post(
     *     tags={"Assignment"},
     *     path="/assignment/create",
     *     summary="Create Vehicle Assignment Info",
     *     operationId="CreateVehicleAssignment",
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         description="Vehicle Assignment Information",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                  @OA\Property(
     *                     property="vehicle_id",
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
     *                 example={"vehicle_id": 1, "vehicle_status": 4,
     *                          "driver_name": "Juan Dela Cruz","mileage": 1825, }
     *             )
     *         )
     *     ),
     
     *     @OA\Response(
     *         response=200,
     *         description="Vehicle assignment is successfully registered",
     *         @OA\JsonContent(ref="#/components/schemas/VehicleAssignment")
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
     *          description="Vehicle assignment already exist!",
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
        $isExist = VehicleAssignment::where('vehicle_id', $request->vehicle_id)->where('vehicle_status', $request->vehicle_status)
            ->where('driver_name', $request->driver_name)->where('mileage', $request->mileage)->exists();

        if ($isExist)
            return $response->ErrorResponse('Vehicle assignment already exist!', 409);

        else {
            $newVA = VehicleAssignment::create([
                'vehicle_id' => $request->vehicle_id,
                'vehicle_status' => $request->vehicle_status,
                'driver_name' => $request->driver_name,
                'mileage' => $request->mileage,
                'register_by_user_id' => Auth::user()->id
            ]);

            if ($newVA) {
                $newVARec = $this->assignmentById($newVA->id);

                $responseData = ['vehicle-assignment' => $newVARec];
                return $response->SuccessResponse('Vehicle assignment is successfully registered', $responseData);
            }

            return $response->ErrorResponse('Server Error', 500);
        }
    }

    /**
     * @OA\Get(
     *     tags={"Assignment"},
     *     path="/assignment/assignmentById/{id}",
     *     summary="Get vehicle assignment by id",
     *     operationId="GetVehicleAssignmentById",
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
     *         @OA\JsonContent(ref="#/components/schemas/VehicleAssignment")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Customer not found"
     *     ),
     * )
     */
    public function assignmentById($id)
    {
        $vehicleAssign = VehicleAssignment::with(['vehicle', 'register_by', 'updated_by'])->find($id);

        if ($vehicleAssign) {
            return $this->hideFields($vehicleAssign);
        }

        $response = new ApiResponse();
        return $response->ErrorResponse('Vehicle assignment not found!', 404);
    }

    /**
     * @OA\Post(
     *     tags={"Assignment"},
     *     path="/assignment/list",
     *     summary="Get list of Assignments",
     *     operationId="AssignmentList",
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         description="NOTE: If parameters are omitted then all vehicle assignments will be return.",
     *         required=false,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="vehicle_id",
     *                     type="integer"
     *                 ),
     *                 example={"vehicle_id": 0}
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
        $req = VehicleAssignment::select();

        if ($request->vehicle_id)
            $req->where('vehicle_id', $request->vehicle_id);

        $data = $req->with(['vehicle', 'register_by', 'updated_by'])->get();
        foreach ($data as $rec) {
            $this->hideFields($rec);
        }

        return $data;
    }

    /**
     * @OA\Put(
     *     tags={"Assignment"},
     *     path="/assignment/update/{id}",
     *     summary="Updated Assignment",
     *     description="Update Assignment information.",
     *     operationId="UpdateAssignment",
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
     *         description="Updated Vehicle Assignment object",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                  @OA\Property(
     *                     property="id",
     *                     type="integer"
     *                 ),
     *                  @OA\Property(
     *                     property="vehicle_id",
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
     *                 example={"id": 1, "vehicle_id": 1, "vehicle_status": 4,
     *                          "driver_name": "Juan Dela Cruz","mileage": 1825, }
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Vehicle Assignment is successfully updated!"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Vehicle Assignment not found"
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Vehicle Assignment Id does not matched!"
     *     )
     * )
     */
    public function update($id, Request $request)
    {
        $response = new ApiResponse();

        if ($id == $request->id) {
            $VA = VehicleAssignment::find($id);

            if ($VA) {
                // Forwarding of DEVICE and VEHICLE info to WLOC-MP Integration Server
                // - If vehicle_status == 1 (Approved), check if DEVICE and VEHICLE is already registered in WLOC-MP Integration Server
                if ($request->vehicle_status === 1) {
                   
                    // Get vehicle info
                    $vehicleCtrl = new VehicleController();
                    $vehicle = $vehicleCtrl->vehicleById($request->vehicle_id);
                    
                    $integration = new IntegrationController($vehicle->device_id_plate_no, $vehicle->mileage, $vehicle->driver_name);

                    // If device and vehicle are successfully uploaded to integration server
                    // update vehicle status to approved in mysql server
                    $uploadResult = $integration->uploading();

                    if ($uploadResult == 200 || $uploadResult == 409)
                        $this->updateInfo($VA, $request);

                    else
                        return $response->ErrorResponse('Failed, something went wrong in integration server', 500);
                } else
                    $this->updateInfo($VA, $request->collect());

                $VAData = $this->assignmentById($VA->id);
                return $response->SuccessResponse('Vehicle Assignment is successfully updated!', $VAData);
            }

            return $response->ErrorResponse('Vehicle Assignment not found!', 404);
        }

        return $response->ErrorResponse('Vehicle Assignment Id does not matched!', 409);
    }

    private function updateInfo($VA, $request)
    {
        $VA->update([
            'vehicle_id' => $request['vehicle_id'],
            'vehicle_status' => $request['vehicle_status'],
            'driver_name' => $request['driver_name'],
            'mileage' => $request['mileage'],
            'updated_by_user_id' => Auth::user()->id
        ]);
    }

    /**
     * @OA\Put(
     *     tags={"Assignment"},
     *     path="/assignment/update-assign-customer/{id}",
     *     summary="Updated Assignment and Customer",
     *     description="Update Assignment and Customer information.",
     *     operationId="UpdateAssignmentCust",
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
     *         description="Updated Vehicle Assignment and Customer object",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                  @OA\Property(
     *                     property="id",
     *                     type="integer"
     *                 ),
     *                  @OA\Property(
     *                     property="vehicle_id",
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
     *                     property="current_customer_id",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="customer_id",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="ipport_id",
     *                     type="integer"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Vehicle Assignment and Customer is successfully updated!"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Vehicle Assignment not found"
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Vehicle Assignment Id does not matched!"
     *     )
     * )
     */
    public function updateAssignmentCustomer($id, Request $request)
    {
        $response = new ApiResponse();
        $isAssignmentChange = false;

        if ($id == $request->id) {
            $VA = VehicleAssignment::find($id);

            if ($VA->vehicle_id != $request->vehicle_id) $isAssignmentChange = true;
            if ($VA->driver_name != $request->driver_name) $isAssignmentChange = true;
            if ($VA->mileage != $request->mileage) $isAssignmentChange = true;

            if ($isAssignmentChange) {
                // if ipport_id is present in request body that means Operator/Admin is doing the update
                // Set status to approved once Operator/Admin is doing the update
                if(!$request->missing('ipport_id'))
                {
                    $request['vehicle_status'] = 1;
                    $updateAssign = $this->update($id, $request);

                    if($updateAssign->status() == 200)
                    {
                        $currCust = new CurrentCustomerController();
                        $currCust_req = new Request();
                        $currCust_req['id'] = $request->current_customer_id;
                        $currCust_req['vehicle_assignment_id'] = $request->id;
                        $currCust_req['customer_id'] = $request->customer_id;
                        $currCust_req['ipport_id'] = $request->ipport_id;

                        $updateCurrCust = $currCust->update($request->current_customer_id, $currCust_req);
                    
                        if($updateCurrCust->status() == 200)
                            return response('Successfully updated!', 200);

                        return response('Current - Failed to update!', $updateCurrCust->status());
                    }

                    return response('Assignment - Failed to update!', $updateAssign->status());
                }

                // Update is done by Transporter, create new assignment record
                else {
                    $request['vehicle_status'] = 4;
                    $createAssign = $this->create($request);

                    if($createAssign->status() == 200) {
                        $assignResponse = (json_decode(json_encode($createAssign), true)['original']);
                    
                        $currCust = new CurrentCustomerController();
                        $currCust_req = new Request();
                        $currCust_req['id'] = $request->current_customer_id;
                        $currCust_req['vehicle_assignment_id'] = $assignResponse['data']['vehicle-assignment']['id'];
                        $currCust_req['customer_id'] = $request->customer_id;
                        $currCust_req['ipport_id'] = $request->ipport_id;
                        $currCustUpdate = $currCust->update($request->current_customer_id, $currCust_req);

                        if($currCustUpdate->status() == 200)
                            return response('Vehicle successfully created!', 200);

                        return response('Current - Failed to update!', $currCustUpdate->status());
                    }

                    return response('Assignment - Failed to update!', $createAssign->status());
                }
            }

            else {
                $currCust = new CurrentCustomerController();
                $currCust_req = new Request();
                $currCust_req['id'] = $request->current_customer_id;
                $currCust_req['vehicle_assignment_id'] = $request->id;
                $currCust_req['customer_id'] = $request->customer_id;
                $currCust_req['ipport_id'] = $request->ipport_id;
                $updateCurrCust = $currCust->update($request->current_customer_id, $currCust_req);
            
                if($updateCurrCust->status() == 200)
                    return response('Successfully updated!', 200);

                return response('Failed to update!', $updateCurrCust->status());
            }
        }

        return $response->ErrorResponse('Vehicle Assignment Id does not matched!', 409);
    }

    /**
     * @OA\Delete(
     *     tags={"Assignment"},
     *     path="/assignment/delete/{id}",
     *     summary="Delete Assignment by id",
     *     operationId="DeleteAssignment",
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
     *         description="Vehicle assignment is successfully deleted!",
     *         @OA\JsonContent(ref="#/components/schemas/VehicleAssignment")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Vehicle assignment does not exist!"
     *     ),
     * )
     */
    public function delete($id)
    {
        $response = new ApiResponse();
        $VA = VehicleAssignment::find($id);

        if ($VA) {
            $VA->delete();
            return $response->SuccessResponse('Vehicle assignment is successfully deleted!', $VA);
        }

        return $response->ErrorResponse('Vehicle assignment does not exist!', 404);
    }

    private function hideFields($vehicleAssign)
    {
        if ($vehicleAssign->vehicle)
            $vehicleAssign->vehicle->makeHidden(['created_at', 'updated_at']);

        if ($vehicleAssign->register_by)
            $vehicleAssign->register_by->makeHidden(['username_email', 'transporter_id', 'contact_no', 'user_role', 'email_verified_at', 'first_login']);

        if ($vehicleAssign->updated_by)
            $vehicleAssign->updated_by->makeHidden(['username_email', 'transporter_id', 'contact_no', 'user_role', 'email_verified_at', 'first_login']);

        return $vehicleAssign;
    }
}
