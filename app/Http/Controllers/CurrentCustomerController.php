<?php

namespace App\Http\Controllers;

use App\Http\Response\ApiResponse;
use App\Models\CurrentCustomer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CurrentCustomerController extends Controller
{
    /**
     * @OA\Post(
     *     tags={"Current"},
     *     path="/current/create",
     *     summary="Create Current Customer Info",
     *     operationId="CreateCurrentCustomer",
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         description="Customer Customer Information",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                  @OA\Property(
     *                     property="vehicle_assignment_id",
     *                     type="integer"
     *                 ),
     *                  @OA\Property(
     *                     property="customer_id",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="ipport_id",
     *                     type="integer"
     *                 ),
     *                 example={"vehicle_assignment_id": 1,
     *                          "customer_id": 1,"ipport_id": 1, }
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Current customer is successfully registered",
     *         @OA\JsonContent(ref="#/components/schemas/CurrentCustomer")
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
     *          description="Current customer already exist!",
     *      ),
     *     @OA\Response(
     *          response=500,
     *          description="Internal Server Error",
     *      ),
     * )
     */
    public function create(Request $request)
    {
        $customer_id = null;
        $currUser = Auth::user();
        if($currUser->user_role === 1){
            if ($request->customer_id){
                $customer_id = $request->customer_id;
            }
        }else if(isset($currUser->customer_id)){
            $customer_id = $currUser->customer_id;
        }else{
            return $response->ErrorResponse('Customer Id does not matched!', 409);
        }

        $response = new ApiResponse();
        $isExist = CurrentCustomer::where('vehicle_assignment_id', $request->vehicle_assignment_id)
                        ->where('customer_id', $customer_id)->where('ipport_id', $request->ipport_id)->exists();

        if ($isExist)
            return $response->ErrorResponse('Current Customer already exist!', 409);

        else {
            $newCC = CurrentCustomer::create([
                'vehicle_assignment_id' => $request->vehicle_assignment_id,
                'customer_id' => $customer_id,
                'ipport_id' => $request->ipport_id,
                'register_by_user_id' => Auth::user()->id
            ]);

            if ($newCC) {
                $newCCRec = $this->currentCustById($newCC->id);

                $responseData = ['current-customer' => $newCCRec];
                return $response->SuccessResponse('Current Customer is successfully registered', $responseData);
            }

            return $response->ErrorResponse('Server Error', 500);
        }
    }

     /**
     * @OA\Get(
     *     tags={"Current"},
     *     path="/current/currentCustById/{id}",
     *     summary="Get current customer by id",
     *     operationId="GetCurrentCustomerById",
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
     *         @OA\JsonContent(ref="#/components/schemas/CurrentCustomer")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Current Customer not found"
     *     ),
     * )
     */
    public function currentCustById($id)
    {
        $cc = CurrentCustomer::with(['vehicleAssignment', 'customer', 'ipport', 'register_by', 'updated_by'])->find($id);

        if ($cc) {
            return $this->hideFields($cc);
        }

        $response = new ApiResponse();
        return $response->ErrorResponse('Current customer not found!', 404);
    }

    /**
     * @OA\Post(
     *     tags={"Current"},
     *     path="/current/list",
     *     summary="Get list of Currents",
     *     operationId="CurrentList",
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         description="Customer Id - NOTE: If customer_id object is omitted then all current customers will be return.",
     *         required=false,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="customer_id",
     *                     type="integer"
     *                 ),
     *                 example={"customer_id": 0}
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
        // $req = CurrentCustomer::select();
        $req = CurrentCustomer::join('customers', 'customers.id', 'current_customers.customer_id')
                ->select('current_customers.*');
        
        $currUser = Auth::user();
        if($currUser->user_role === 1){
            if ($request->customer_id){
                $req->where('current_customers.customer_id', $request->customer_id);
            }                
        }else if(isset($currUser->customer_id)){
            $req->where('current_customers.customer_id', $currUser->customer_id);
        }else{
            return $response->ErrorResponse('Customer Id does not matched!', 409);
        }

        // $data = $req->with(['vehicleAssignment', 'customer', 'ipport', 'register_by', 'updated_by'])->get();
        $data = $req->get();
        foreach ($data as $rec) {
            $this->hideFields($rec);
        }

        return $data;
    }

    /**
     * @OA\Put(
     *     tags={"Current"},
     *     path="/current/update/{id}",
     *     summary="Updated Current",
     *     description="Update Current information.",
     *     operationId="UpdateCurrent",
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
     *         description="Updated Current Customer object",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
    *             @OA\Schema(
     *                  @OA\Property(
     *                     property="id",
     *                     type="integer"
     *                 ),
     *                  @OA\Property(
     *                     property="vehicle_assignment_id",
     *                     type="integer"
     *                 ),
     *                  @OA\Property(
     *                     property="customer_id",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="ipport_id",
     *                     type="integer"
     *                 ),
     *                 example={"id": 1, "vehicle_assignment_id": 1,
     *                          "customer_id": 1,"ipport_id": 1, }
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Current customer is successfully updated!"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Current customer not found"
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Current customer Id does not matched!"
     *     )
     * )
     */
    public function update($id, Request $request)
    {
        $response = new ApiResponse();

        if ($id == $request->id) {
            $cust = CurrentCustomer::find($id);

            if ($cust) {
                $cust->update([
                    'vehicle_assignment_id' => $request['vehicle_assignment_id'],
                    'customer_id' => $request['customer_id'],
                    'ipport_id' => $request['ipport_id'],
                    'updated_by_user_id' => Auth::user()->id
                ]);
                $custData = $this->currentCustById($cust->id);

                return $response->SuccessResponse('Current customer is successfully updated!', $custData);
            }

            return $response->ErrorResponse('Current customer not found!', 404);
        }

        return $response->ErrorResponse('Current customer Id does not matched!', 409);
    }

    // /**
    //  * @OA\Delete(
    //  *     tags={"Current"},
    //  *     path="/current/delete/{id}",
    //  *     summary="Delete Current by id",
    //  *     operationId="DeleteCurrent",
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
    //  *         description="Current customer is successfully deleted!",
    //  *         @OA\JsonContent(ref="#/components/schemas/CurrentCustomer")
    //  *     ),
    //  *     @OA\Response(
    //  *         response=404,
    //  *         description="Current customer does not exist!"
    //  *     ),
    //  * )
    //  */
    // public function delete($id)
    // {
    //     $response = new ApiResponse();
    //     $cust = CurrentCustomer::find($id);

    //     if ($cust) {
    //         $cust->delete();
    //         return $response->SuccessResponse('Current customer is successfully deleted!', $cust);
    //     }

    //     return $response->ErrorResponse('Current customer does not exist!', 404);
    // }

    private function hideFields($customer)
    {
        if ($customer->vehicleAssignment)
            $customer->vehicleAssignment->makeHidden(['created_at', 'updated_at']);

        if ($customer->customer)
            $customer->customer->makeHidden(['customer_address', 'customer_contact_no', 'customer_email', 'created_at', 'updated_at']);

        if ($customer->ipport)
            $customer->ipport->makeHidden(['created_at', 'updated_at']);

        if ($customer->register_by)
            $customer->register_by->makeHidden(['username_email', 'password', 'transporter_id', 'contact_no', 'user_role', 'email_verified_at', 'first_login']);

        if ($customer->updated_by)
            $customer->updated_by->makeHidden(['username_email', 'password', 'transporter_id', 'contact_no', 'user_role', 'email_verified_at', 'first_login']);

        return $customer;
    }
}
