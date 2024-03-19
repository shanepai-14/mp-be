<?php

namespace App\Http\Controllers;

use App\Http\Response\ApiResponse;
use App\Models\Customer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerController extends Controller
{
    /**
     * @OA\Post(
     *     tags={"Customer"},
     *     path="/customer/create",
     *     summary="Create customer",
     *     operationId="CreateCustomer",
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         description="Customer Information",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                  @OA\Property(
     *                     property="customer_name",
     *                     type="string"
     *                 ),
     *                  @OA\Property(
     *                     property="customer_address",
     *                     type="string"
     *                 ),
     *                  @OA\Property(
     *                     property="customer_contact_no",
     *                     type="string"
     *                 ),
     *                  @OA\Property(
     *                     property="customer_email",
     *                     type="string",
     *                     format="email"
     *                 ),
     *                  @OA\Property(
     *                     property="customer_code",
     *                     type="string",
     *                 ),
     *                  @OA\Property(
     *                     property="customer_username",
     *                     type="string",
     *                 ),
     *                  @OA\Property(
     *                     property="customer_password",
     *                     type="string",
     *                 ),
     *                  @OA\Property(
     *                     property="customer_api_key",
     *                     type="string",
     *                 ),
     *                 example={"customer_name": "Customer1", "customer_address": "Singapore",
     *                          "customer_contact_no": "+123123","customer_email": "sample@sample.com",
     *                          "customer_code": "0001", "customer_username": "", "customer_password": "", "customer_api_key": "" }
     *             )
     *         )
     *     ),

     *     @OA\Response(
     *         response=200,
     *         description="Customer is successfully registered",
     *         @OA\JsonContent(ref="#/components/schemas/Customer")
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
     *          description="Customer already exist!",
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
        $isCustomerExist = Customer::where('customer_name', $request->customer_name)->exists();

        if ($isCustomerExist)
            return $response->ErrorResponse('Customer already exist!', 409);

        else {
            if (IntegrationController::validateAccount($request->customer_username, $request->customer_password, $request->customer_api_key)) {
                $newCustomer = Customer::create([
                    'customer_name' => $request->customer_name,
                    'customer_address' => $request->customer_address,
                    'customer_contact_no' => $request->customer_contact_no,
                    'customer_email' => $request->customer_email,
                    'customer_code' => $request->customer_code,
                    'customer_username' => $request->customer_username ? $request->customer_username : null,
                    'customer_password' => $request->customer_password ? Crypt::encryptString($request->customer_password) : null,
                    'customer_api_key' => $request->customer_api_key ? Crypt::encryptString($request->customer_api_key) : null,
                    'register_by_user_id' => Auth::user()->id
                ]);

                if ($newCustomer) {
                    $newCustomerRec = $this->customerById($newCustomer->id);

                    $responseData = ['customer' => $newCustomerRec];
                    return $response->SuccessResponse('Customer is successfully registered', $responseData);
                }

                return $response->ErrorResponse('Server Error', 500);
            }
            else return $response->ErrorResponse('Invalid customer credentials or API key', 400);
        }
    }

    /**
     * @OA\Get(
     *     tags={"Customer"},
     *     path="/customer/customerById/{id}",
     *     summary="Get customer by id",
     *     operationId="GetCustomerById",
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
     *         @OA\JsonContent(ref="#/components/schemas/Customer")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Customer not found"
     *     ),
     * )
     */
    public function customerById($id)
    {
        $customer = Customer::with(['register_by', 'updated_by'])->find($id);

        if ($customer) {
            return $this->hideFields($customer);
        }

        $response = new ApiResponse();
        return $response->ErrorResponse('Customer not found!', 404);
    }

    /**
     * @OA\Post(
     *     tags={"Customer"},
     *     path="/customer/list",
     *     summary="Get list of customers",
     *     operationId="CustomerList",
     *     security={{"bearerAuth": {}}},
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
    public function list()
    {
        $customerReq = Customer::select();

        $data = $customerReq->with(['register_by', 'updated_by'])->get();
        foreach ($data as $rec) {
            $this->hideFields($rec);
        }

        return $data;
    }

    /**
     * @OA\Put(
     *     tags={"Customer"},
     *     path="/customer/update/{id}",
     *     summary="Updated Customer",
     *     description="Update Customer information.",
     *     operationId="UpdateCustomer",
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
     *         description="Updated customer object",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="id",
     *                     type="integer"
     *                 ),
     *                  @OA\Property(
     *                     property="customer_name",
     *                     type="string"
     *                 ),
     *                  @OA\Property(
     *                     property="customer_address",
     *                     type="string"
     *                 ),
     *                  @OA\Property(
     *                     property="customer_contact_no",
     *                     type="string"
     *                 ),
     *                  @OA\Property(
     *                     property="customer_email",
     *                     type="string"
     *                 ),
     *                  @OA\Property(
     *                     property="customer_code",
     *                     type="string"
     *                 ),
     *                  @OA\Property(
     *                     property="customer_username",
     *                     type="string"
     *                 ),
     *                  @OA\Property(
     *                     property="customer_password",
     *                     type="string"
     *                 ),
     *                  @OA\Property(
     *                     property="customer_api_key",
     *                     type="string"
     *                 ),
     *                 example={"id": 0, "customer_name": "Customer1", "customer_address": "Singapore",
     *                          "customer_contact_no": "+123123","customer_email": "sample@sample.com",
     *                          "customer_code": "0001", "customer_username": "", "customer_password": "", "customer_api_key": "" }
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Customer is successfully updated!"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Customer not found"
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Customer Id does not matched!"
     *     )
     * )
     */
    public function update($id, Request $request)
    {
        $response = new ApiResponse();

        if ($id == $request->id) {
            $customer = Customer::find($id);

            if ($customer) {
                $customer->update([
                    'customer_name' => $request['customer_name'],
                    'customer_address' => $request['customer_address'],
                    'customer_contact_no' => $request['customer_contact_no'],
                    'customer_email' => $request['customer_email'],
                    'customer_code' => $request['customer_code'],
                    'customer_username' => $request['customer_username'] ? $request['customer_username'] : null,
                    'customer_password' => $request['customer_password'] ? Crypt::encryptString($request['customer_password']) : null,
                    'customer_api_key' => $request['customer_api_key'] ? Crypt::encryptString($request['customer_api_key']) : null,
                    'updated_by_user_id' => Auth::user()->id
                ]);
                $customerData = $this->customerById($customer->id);

                return $response->SuccessResponse('Customer is successfully updated!', $customerData);
            }

            return $response->ErrorResponse('Customer not found!', 404);
        }

        return $response->ErrorResponse('Customer Id does not matched!', 409);
    }

    /**
     * @OA\Delete(
     *     tags={"Customer"},
     *     path="/customer/delete/{id}",
     *     summary="Delete Customer by id",
     *     operationId="DeleteCustomer",
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
     *         description="Customer is successfully deleted!",
     *         @OA\JsonContent(ref="#/components/schemas/Customer")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Customer does not exist!"
     *     ),
     * )
     */
    public function delete($id)
    {
        $response = new ApiResponse();
        $customer = Customer::find($id);

        if ($customer) {
            $customer->delete();
            return $response->SuccessResponse('Customer is successfully deleted!', $customer);
        }

        return $response->ErrorResponse('Customer does not exist!', 404);
    }

    private function hideFields($customer)
    {
        if ($customer->register_by)
            $customer->register_by->makeHidden(['username_email', 'password', 'transporter_id', 'contact_no', 'user_role', 'email_verified_at', 'first_login']);

        if ($customer->updated_by)
            $customer->updated_by->makeHidden(['username_email', 'password', 'transporter_id', 'contact_no', 'user_role', 'email_verified_at', 'first_login']);

        return $customer;
    }
}
