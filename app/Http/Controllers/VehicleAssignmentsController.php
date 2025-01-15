<?php

namespace App\Http\Controllers;

use App\Http\Response\ApiResponse;
use App\Models\CurrentCustomer;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
     *                     description="",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="customer_code",
     *                     description="",
     *                     type="string"
     *                 ),
     *                 example={"vehicle_id": 1, "vehicle_status": 4,
     *                          "driver_name": "Juan Dela Cruz","mileage": 1825, "customer_code": "ICPL, Alliance" }
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

            if(!preg_match('/^[a-z0-9 .\-]+$/i', $request->driver_name)){
                return $response->ErrorResponse('Driver name contains non-alphanumerical character(s)', 400);
            }

            $newVA = VehicleAssignment::create([
                'vehicle_id' => $request->vehicle_id,
                'vehicle_status' => $request->vehicle_status,
                //'driver_name' => $request->driver_name,
                'driver_name' => "",
                'mileage' => $request->mileage,
                'customer_code' => $request->customer_code,
                'register_by_user_id' => Auth::user()->id
            ]);

            // if ($newVA) {
            //     $newVARec = $this->assignmentById($newVA->id);
            //     if ($request->vehicle_status === 1) {
            //         $request['id'] = $newVA->id;
            //         $uploadVehicleReq = $this->update($newVA->id, $request);
            //         $uploadVehicleRes = (json_decode(json_encode($uploadVehicleReq), true)['original']);
            //         $reqStatus = $uploadVehicleReq->status();
            //         if ($reqStatus !== 200) {
            //             return $response->ErrorResponse($uploadVehicleRes['message'] ?? 'Create assignment - something went wrong in integration server', $reqStatus);
            //         }
            //     }
            // }

            $newVARec = $this->assignmentById($newVA->id);
            $responseData = ['vehicle-assignment' => $newVARec];
            return $response->SuccessResponse('Vehicle assignment is successfully registered', $responseData);

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
     *                 @OA\Property(
     *                     property="vehicle_status",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="vendor_id",
     *                     type="integer"
     *                 ),
     *                 example={"vehicle_id": 0, "vehicle_status": 0, "vendor_id": 0}
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
        // $req = VehicleAssignment::select();
        // $req = VehicleAssignment::join('vehicles', 'vehicles.id', 'vehicle_assignments.vehicle_id')
        //     ->select('vehicle_assignments.*', 'vehicles.transporter_id');

        $req = VehicleAssignment::select(
            'vehicle_assignments.id',
            'vehicle_assignments.vehicle_id',
            'vehicle_assignments.vehicle_status',
            'vehicle_assignments.driver_name',
            'vehicle_assignments.mileage',
            'vehicle_assignments.customer_code',
            'vehicle_assignments.created_at'
        )
            ->join(DB::raw('(SELECT vehicle_id, MAX(created_at) AS created_at FROM vehicle_assignments GROUP BY vehicle_id) as t2'), function ($join) {
                $join->on('vehicle_assignments.vehicle_id', '=', 't2.vehicle_id');
                $join->on('vehicle_assignments.created_at', '=', 't2.created_at');
            })
            ->join('vehicles', 'vehicles.id', '=', 'vehicle_assignments.vehicle_id');

        if ($request->vehicle_id)
            $req->where('vehicle_assignments.vehicle_id', $request->vehicle_id);

        if ($request->vehicle_status)
            $req->where('vehicle_assignments.vehicle_status', $request->vehicle_status);


        $currUser = Auth::user();
        if($currUser->user_role === 1){
            if ($request->vendor_id)
                $req->where('vehicles.transporter_id', $request->vendor_id);
        }else if(isset($currUser->vendor_id)){
            $req->where('vehicles.transporter_id', $currUser->vendor_id);
        }else{
            $response = new ApiResponse();
            return $response->ErrorResponse('Vendor Id does not matched!', 409);
        }

        // $data = $req->with(['vehicle', 'register_by', 'updated_by'])->get();
        $data = $req->get();
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
     *                  @OA\Property(
     *                     property="customer_code",
     *                     type="string"
     *                 ),
     *                 example={"id": 1, "vehicle_id": 1, "vehicle_status": 4,
     *                          "driver_name": "Juan Dela Cruz","mileage": 1825, "customer_code": "ICPL, Alliance" }
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

                if(!preg_match('/^[a-z0-9 .\-]+$/i', $request->driver_name)){
                    return $response->ErrorResponse('Driver name contains non-alphanumerical character(s)', 400);
                }

                if ($request->vehicle_status === 1) {

                    // Get vehicle info
                    $vehicleCtrl = new VehicleController();
                    $vehicle = $vehicleCtrl->vehicleById($request->vehicle_id);

                    // Get current customer
                    $currentCustomer = CurrentCustomer::with('customer', 'ipport')->where('vehicle_assignment_id', $VA->id)->first();

                    $integration = new IntegrationController($currentCustomer['customer'], $vehicle, $VA, $currentCustomer['ipport']);

                    //Checks if there is username and password or api key
                    if ($integration->hasLoginCredentials()) {
                        // If device and vehicle are successfully uploaded to integration server
                        // update vehicle status to approved in mysql server
                        $uploadResult = $integration->uploading();
                        $uploadResult = (json_decode(json_encode($uploadResult), true)['original']);

                        if ($uploadResult['status'] === 200)  $this->updateInfo($VA, $request);
//                         if (true) $this->updateInfo($VA, $request);
                        else return $response->ErrorResponse($uploadResult['message'] ?? 'Failed, something went wrong in integration server', 500);
                    } else {
                        return $response->ErrorResponse("There's no integration login credentials found!", 400);
                    }
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
            //'driver_name' => $request['driver_name'],
            'driver_name' => "",
            'mileage' => $request['mileage'],
            'customer_code' => $request->has('customer_code') ? $request['customer_code'] : $VA->customer_code,
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
     *                  @OA\Property(
     *                     property="customer_code",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="customers",
     *                     type="array",
     *                     @OA\Items(
     *                      @OA\Property(
     *                          property="current_customer_id",
     *                          type="integer"
     *                      ),
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
            $customerCount = count($request->customers ?? []);
            $currCustomers = [];

            if ($VA->vehicle_id != $request->vehicle_id) $isAssignmentChange = true;
            if ($VA->driver_name != $request->driver_name) $isAssignmentChange = true;
            if ($VA->mileage != $request->mileage) $isAssignmentChange = true;
            if ($customerCount === 0 && $VA->customer_code != $request->customer_code) $isAssignmentChange = true;
            if ($VA->vehicle_status !== 1 && $customerCount > 0) $isAssignmentChange = true;

            if(!preg_match('/^[a-z0-9 .\-]+$/i', $request->driver_name)){
                return $response->ErrorResponse('Driver name contains non-alphanumerical character(s)', 400);
            }

            if ($isAssignmentChange) {
                // if ipport_id is present in request body that means Operator/Admin is doing the update
                // Set status to approved once Operator/Admin is doing the update
                // Only the admin can select customers
                if ($customerCount > 0) {
                    $request['vehicle_status'] = 1;
                    $customerCount = count($request->customers ?? []);

                    if ($customerCount > 0) {
                        //Create or update current customers
                        $currCust = new CurrentCustomerController();
                        for ($i = 0; $i < $customerCount; $i++) {
                            $currentCustomer = $request->customers[$i];
                            $currCust_req = new Request();
                            $hasCurrCustomerId = array_key_exists('current_customer_id', $currentCustomer);
                            $currCust_req['id'] = $hasCurrCustomerId ? $currentCustomer['current_customer_id'] : null;
                            $currCust_req['vehicle_assignment_id'] = $request->id;
                            $currCust_req['customer_id'] = $currentCustomer['customer_id'];
                            $currCust_req['ipport_id'] = $currentCustomer['ipport_id'];

                            if ($hasCurrCustomerId) {
                                $updateCurrCust = $currCust->update($currCust_req['id'], $currCust_req);
                                $updateCurrCustRes = (json_decode(json_encode($updateCurrCust), true)['original']);

                                if ($updateCurrCust->status() !== 200) {
                                    return $response->ErrorResponse($updateCurrCustRes['message'] ?? 'Failed to update!', $updateCurrCust->status());
                                }

                                array_push($currCustomers, $updateCurrCustRes['data']);
                            } else {
                                $currCustCreate = $currCust->create($currCust_req);
                                $currCustCreateRes = (json_decode(json_encode($currCustCreate), true)['original']);

                                if ($currCustCreate->status() !== 200) {
                                    return $response->ErrorResponse($currCustCreateRes['message'] ?? 'Failed to update!', $currCustCreate->status());
                                }

                                array_push($currCustomers, $currCustCreateRes['data']['current-customer']);
                            }
                        }

                        if (count($currCustomers) > 0) {
                            //Update customer code based on the current customer records
                            $updateVehicleAssignment = VehicleAssignment::find($request->id);
                            $updateVehicleAssignment['customer_code'] = join(", ", array_unique(array_map(function ($value) {
                                return $value['customer']['customer_code'];
                            }, $currCustomers)));
                            $updateVehicleAssignment->save();
                            $assignResponse['data'] = $updateVehicleAssignment;
                        }
                    }

                    //Update device and vehicle information on integration
                    $updateAssign = $this->update($id, $request);
                    $assignResponse = (json_decode(json_encode($updateAssign), true)['original']);

                    if ($updateAssign->status() == 200) {
                        return $response->SuccessResponse('Successfully updated!', [
                            'vehicle_assignment' => $assignResponse['data'],
                            'current_customer' => $currCustomers,
                        ]);
                    }

                    return $response->ErrorResponse($assignResponse['message'] ?? 'Assignment - Failed to update!', $updateAssign->status());
                }

                // Update is done by Transporter, create new assignment record
                else {
                    $request['vehicle_status'] = 4;
                    $createAssign = $this->create($request);
                    $assignResponse = (json_decode(json_encode($createAssign), true)['original']);

                    if ($createAssign->status() == 200) {
                        return $response->SuccessResponse('Vehicle successfully created!', [
                            'vehicle_assignment' => $assignResponse['data']['vehicle-assignment'],
                        ]);
                    }

                    return $response->ErrorResponse($assignResponse['message'] ?? 'Assignment - Failed to update!', $createAssign->status());
                }
            } else {
                if ($customerCount > 0) {
                    $currCust = new CurrentCustomerController();

                    for ($i = 0; $i < $customerCount; $i++) {
                        $currentCustomer = $request->customers[$i];
                        $currCust_req = new Request();
                        $hasCurrCustomerId = array_key_exists('current_customer_id', $currentCustomer);
                        $currCust_req['id'] = $hasCurrCustomerId ? $currentCustomer['current_customer_id'] : null;
                        $currCust_req['vehicle_assignment_id'] = $request->id;
                        $currCust_req['customer_id'] = $currentCustomer['customer_id'];
                        $currCust_req['ipport_id'] = $currentCustomer['ipport_id'];

                        if ($hasCurrCustomerId) {
                            $updateCurrCust = $currCust->update($currCust_req['id'], $currCust_req);
                            $updateCurrCustRes = (json_decode(json_encode($updateCurrCust), true)['original']);
                            if ($updateCurrCust->status() !== 200) {
                                return $response->ErrorResponse($updateCurrCustRes['message'] ?? 'Failed to update!', $updateCurrCust->status());
                            }

                            array_push($currCustomers, $updateCurrCustRes['data']);
                        } else {
                            $currCustCreate = $currCust->create($currCust_req);
                            $updateCurrCustRes = (json_decode(json_encode($currCustCreate), true)['original']);
                            if ($currCustCreate->status() !== 200) {
                                return $response->ErrorResponse($updateCurrCustRes['message'] ?? 'Failed to update!', $currCustCreate->status());
                            }

                            array_push($currCustomers, $updateCurrCustRes['data']['current-customer']);
                        }
                    }

                    //Update customer code based on the current customer records
                    $updateVehicleAssignment = VehicleAssignment::find($request->id);
                    $updateVehicleAssignment['customer_code'] = join(", ", array_unique(array_map(function ($value) {
                        return $value['customer']['customer_code'];
                    }, $currCustomers)));
                    $updateVehicleAssignment->save();

                    //Update device and vehicle information on integration
                    $updateAssign = $this->update($id, $request);
                    $assignResponse = (json_decode(json_encode($updateAssign), true)['original']);

                    if ($updateAssign->status() === 200) {
                        return $response->SuccessResponse('Successfully updated!', [
                            'vehicle_assignment' => $updateVehicleAssignment,
                            'current_customer' => $currCustomers
                        ]);
                    }
                    else return $response->ErrorResponse($assignResponse['message'] ?? 'Assignment - Failed to update!', $updateAssign->status());
                } else {
                    return $response->SuccessResponse('No Changes!', []);
                }
            }
        }

        return $response->ErrorResponse('Vehicle Assignment Id does not matched!', 409);
    }

    // /**
    //  * @OA\Delete(
    //  *     tags={"Assignment"},
    //  *     path="/assignment/delete/{id}",
    //  *     summary="Delete Assignment by id",
    //  *     operationId="DeleteAssignment",
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
    //  *         description="Vehicle assignment is successfully deleted!",
    //  *         @OA\JsonContent(ref="#/components/schemas/VehicleAssignment")
    //  *     ),
    //  *     @OA\Response(
    //  *         response=404,
    //  *         description="Vehicle assignment does not exist!"
    //  *     ),
    //  * )
    //  */
    // public function delete($id)
    // {
    //     $response = new ApiResponse();
    //     $VA = VehicleAssignment::find($id);

    //     if ($VA) {
    //         $VA->delete();
    //         return $response->SuccessResponse('Vehicle assignment is successfully deleted!', $VA);
    //     }

    //     return $response->ErrorResponse('Vehicle assignment does not exist!', 404);
    // }

    /**
     * @OA\Put(
     *     tags={"Assignment"},
     *     path="/assignment/approve/{id}",
     *     summary="Approve assignment",
     *     description="Approve vehicle assignment.",
     *     operationId="ApproveVehicle",
     *     security={{"bearerAuth": {}}},
     *  @OA\Parameter(
     *         in="path",
     *         name="id",
     *         required=true,
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *      @OA\RequestBody(
     *         description="",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                  @OA\Property(
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
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Vehicle assignment is approved!"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Vehicle Assignment not found!"
     *     ),
     * )
     */
    public function approve($id, Request $request)
    {
        $response = new ApiResponse();
        $VA = VehicleAssignment::find($id);

        if ($VA) {
            $currCustomerCreated = [];
            $currCust = new CurrentCustomerController();
            for ($i = 0; $i < count($request->customers); $i++) {
                $currentCustomer = $request->customers[$i];
                $isCCExisted = CurrentCustomer::where([
                    ['vehicle_assignment_id', $VA->id],
                    ['customer_id', $currentCustomer['customer_id']],
                    ['ipport_id', $currentCustomer['ipport_id']]
                ])->count() > 0;

                if (!$isCCExisted) {
                    $currCust_req = new Request();
                    $currCust_req['vehicle_assignment_id'] = $VA->id;
                    $currCust_req['customer_id'] = $currentCustomer['customer_id'];
                    $currCust_req['ipport_id'] = $currentCustomer['ipport_id'];
                    $currCustCreate = $currCust->create($currCust_req);
                    $currCustCreateRes = (json_decode(json_encode($currCustCreate), true)['original']);
                    if ($currCustCreate->status() !== 200) {
                        return $response->ErrorResponse($currCustCreateRes['message'] ?? 'Failed to update!', $currCustCreate->status());
                    }

                    array_push($currCustomerCreated, $currCustCreateRes['data']['current-customer']);
                }
            }

            $request['id'] = $VA->id;
            $request['vehicle_status'] = 1;
            $request['vehicle_id'] = $VA->vehicle_id;
            $request['driver_name'] = $VA->driver_name;
            $request['mileage'] = $VA->mileage;
            if (count($currCustomerCreated) > 0) {
                $request['customer_code'] = join(", ", array_unique(array_map(function ($value) {
                    return $value['customer']['customer_code'];
                }, $currCustomerCreated)));
            }
            $assignmentReq = $this->update($id, $request);
            $assignmentRes = (json_decode(json_encode($assignmentReq), true)['original']);
            if ($assignmentReq->status() === 200) {
                return $response->SuccessResponse('Vehicle assignment is approved!', 200);
            } else return $response->ErrorResponse($assignmentRes['message'] ?? 'Failed to update vehicle assignment!', 400);
        } else return $response->ErrorResponse('Vehicle Assignment not found!', 404);
    }

    /**
     * @OA\delete(
     *     tags={"Assignment"},
     *     path="/assignment/reject/{id}",
     *     summary="Reject vehicle assignment",
     *     description="Reject vehicle assignment.",
     *     operationId="RejectVehicle",
     *     security={{"bearerAuth": {}}},
     *  @OA\Parameter(
     *         in="path",
     *         name="id",
     *         required=true,
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Vehicle assignment is rejected!"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Vehicle assignment not found"
     *     ),
     * )
     */
    public function reject($id, Request $request)
    {
        $response = new ApiResponse();
        $VA = VehicleAssignment::find($id);

        if ($VA) {
            $request['vehicle_id'] = $VA->vehicle_id;
            $request['driver_name'] = $VA->driver_name;
            $request['mileage'] = $VA->mileage;
            $request['vehicle_status'] = 2;
            $assignmentReq = $this->update($id, $request);
            $assignmentRes = (json_decode(json_encode($assignmentReq), true)['original']);
            if ($assignmentReq->status() === 200) {
                return $response->SuccessResponse('Vehicle assignment is rejected!', 200);
            } else return $response->ErrorResponse($assignmentRes['message'] ?? 'Failed to update vehicle assignment!', 400);
        } else return $response->ErrorResponse('Vehicle Assignment not found!', 404);
    }

    private function hideFields($vehicleAssign)
    {
        if ($vehicleAssign->vehicle)
            $vehicleAssign->vehicle->makeHidden(['created_at', 'updated_at']);

        if ($vehicleAssign->register_by)
            $vehicleAssign->register_by->makeHidden(['username_email', 'password', 'transporter_id', 'contact_no', 'user_role', 'email_verified_at', 'first_login']);

        if ($vehicleAssign->updated_by)
            $vehicleAssign->updated_by->makeHidden(['username_email', 'password', 'transporter_id', 'contact_no', 'user_role', 'email_verified_at', 'first_login']);

        return $vehicleAssign;
    }
}
