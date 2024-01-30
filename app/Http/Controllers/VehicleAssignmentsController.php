<?php

namespace App\Http\Controllers;

use App\Http\Response\ApiResponse;
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
     *                  @OA\Property(
     *                     property="driver_name",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="mileage",
     *                     type="integer"
     *                 ),
     *                 example={"vehicle_id": 1, 
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
        $isExist = VehicleAssignment::where('vehicle_id', $request->vehicle_id)
                        ->where('driver_name', $request->driver_name)->where('mileage', $request->mileage)->exists();

        if ($isExist)
            return $response->ErrorResponse('Vehicle assignment already exist!', 409);

        else {
            $newVA = VehicleAssignment::create([
                'vehicle_id' => $request->vehicle_id,
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
     *         description="Vehicle Id - NOTE: If vehicle_id object is omitted then all vehicle assignments will be return.",
     *         required=false,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="vehicle_id",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="transporter_id",
     *                     type="integer"
     *                 ),
     *                 example={"vehicle_id": 0, "transporter_id": 0}
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
        $req = VehicleAssignment::join('vehicles', 'vehicles.id', 'vehicle_assignments.vehicle_id')->select();

        if ($request->vehicle_id)
            $req->where('vehicle_assignments.vehicle_id', $request->vehicle_id);

        if ($request->transporter_id)
            $req->where('vehicles.transporter_id', $request->transporter_id);


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
     *                  @OA\Property(
     *                     property="driver_name",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="mileage",
     *                     type="integer"
     *                 ),
     *                 example={"id": 1, "vehicle_id": 1, 
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
                $VA->update([
                    'vehicle_id' => $request['vehicle_id'],
                    'driver_name' => $request['driver_name'],
                    'mileage' => $request['mileage'],
                    'updated_by_user_id' => Auth::user()->id
                ]);
                $VAData = $this->assignmentById($VA->id);

                return $response->SuccessResponse('Vehicle Assignment is successfully updated!', $VAData);
            }

            return $response->ErrorResponse('Vehicle Assignment not found!', 404);
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
            $vehicleAssign->register_by->makeHidden(['username_email', 'password', 'transporter_id', 'contact_no', 'user_role', 'email_verified_at', 'first_login']);

        if ($vehicleAssign->updated_by)
            $vehicleAssign->updated_by->makeHidden(['username_email', 'password', 'transporter_id', 'contact_no', 'user_role', 'email_verified_at', 'first_login']);

        return $vehicleAssign;
    }
}
