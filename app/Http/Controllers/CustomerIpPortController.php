<?php

namespace App\Http\Controllers;

use App\Http\Response\ApiResponse;
use App\Models\CustomerIpPorts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerIpPortController extends Controller
{
    /**
     * @OA\Post(
     *     tags={"Customer"},
     *     path="/customer/ip-port/create",
     *     summary="Create Customer IP and Port",
     *     operationId="CreateCustomerIpPort",
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         description="Customer IP and Port Information",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                  @OA\Property(
     *                     property="customer_id",
     *                     type="integer"
     *                 ),
     *                  @OA\Property(
     *                     property="ip",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="port",
     *                     type="integer"
     *                 ),
     *                 example={"customer_id": 1,
     *                          "ip": "123.123.123.123","port": 123, }
     *             )
     *         )
     *     ),

     *     @OA\Response(
     *         response=200,
     *         description="Customer IP and Port is successfully registered",
     *         @OA\JsonContent(ref="#/components/schemas/CustomerIpPorts")
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
     *          description="Customer IP and Port already exist!",
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
        $isIpPortExist = CustomerIpPorts::where('customer_id', $request->customer_id)
                        ->where('ip', $request->ip)->where('port', $request->port)->exists();

        if ($isIpPortExist)
            return $response->ErrorResponse('Customer IP and Port already exist!', 409);

        else {
            $newIpPort = CustomerIpPorts::create([
                'customer_id' => $request->customer_id,
                'ip' => $request->ip,
                'port' => $request->port,
                'register_by_user_id' => Auth::user()->id
            ]);

            if ($newIpPort) {
                $newIpPortRec = $this->ipPortById($newIpPort->id);

                $responseData = ['customer-ip-port' => $newIpPortRec];
                return $response->SuccessResponse('Customer IP and Port are successfully registered', $responseData);
            }

            return $response->ErrorResponse('Server Error', 500);
        }
    }

     /**
     * @OA\Get(
     *     tags={"Customer"},
     *     path="/customer/ip-port/ipPortById/{id}",
     *     summary="Get Customer IP and Port by id",
     *     operationId="GetCustomerIpPortById",
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
     *         @OA\JsonContent(ref="#/components/schemas/CustomerIpPorts")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Customer IP and Port not found"
     *     ),
     * )
     */
    public function ipPortById($id)
    {
        $customerIpPort = CustomerIpPorts::with(['customer'])->find($id);

        if ($customerIpPort) {
            return $this->hideFields($customerIpPort);
        }

        $response = new ApiResponse();
        return $response->ErrorResponse('Customer not found!', 404);
    }

     /**
     * @OA\Post(
     *     tags={"Customer"},
     *     path="/customer/ip-port/list",
     *     summary="Get list of Customer`s IP and Ports",
     *     operationId="CustomerIpPortList",
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         description="Customer Id - NOTE: If customer_id object is omitted then all customers ip and port will be return.",
     *         required=false,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="customer_id",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="vendor_id",
     *                     type="integer"
     *                 ),
     *                 example={"customer_id": 0, "vendor_id": 0}
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
        // $ipPorts = CustomerIpPorts::with(['customer'])->select();
        $ipPorts = CustomerIpPorts::select();

        if ($request->customer_id)
            $ipPorts->where('customer_ip_ports.customer_id', $request->customer_id);

        $data = $ipPorts->get();
        foreach ($data as $rec) {
            $this->hideFields($rec);
        }

        return $data;
    }

     /**
     * @OA\Put(
     *     tags={"Customer"},
     *     path="/customer/ip-port/update/{id}",
     *     summary="Updated Customer IP and Ports",
     *     description="Update Customer IP and Port information.",
     *     operationId="UpdateCustomerIPPORT",
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
     *         description="Updated Customer IP and Port object",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="id",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="customer_id",
     *                     type="integer"
     *                 ),
     *                  @OA\Property(
     *                     property="ip",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="port",
     *                     type="integer"
     *                 ),
     *                 example={"id": 0, "customer_id": 1,
     *                          "ip": "123.123.123.123","port": 123, }
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Customer IP and Port is successfully updated!"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Customer IP and Port not found"
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
            $custIpPort = CustomerIpPorts::find($id);

            if ($custIpPort) {
                $custIpPort->update($request->all());
                $custIpPortData = $this->ipPortById($custIpPort->id);

                return $response->SuccessResponse('Customer IP and Port is successfully updated!', $custIpPortData);
            }

            return $response->ErrorResponse('Customer IP and Port not found!', 404);
        }

        return $response->ErrorResponse('Customer Id does not matched!', 409);
    }

    /**
     * @OA\Delete(
     *     tags={"Customer"},
     *     path="/customer/ip-port/delete/{id}",
     *     summary="Delete Customer IP and Port by id",
     *     operationId="DeleteCustomerIpPort",
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
     *         description="Customer IP and Port is successfully deleted!",
     *         @OA\JsonContent(ref="#/components/schemas/CustomerIpPorts")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Customer IP and Port does not exist!"
     *     ),
     * )
     */
    public function delete($id)
    {
        $response = new ApiResponse();
        $customer = CustomerIpPorts::find($id);

        if ($customer) {
            $customer->delete();
            return $response->SuccessResponse('Customer IP and Port is successfully deleted!', $customer);
        }

        return $response->ErrorResponse('Customer IP and Port does not exist!', 404);
    }

    private function hideFields($custIpPort)
    {
        if ($custIpPort->customer)
            $custIpPort->customer->makeHidden(['customer_address', 'customer_contact_no', 'customer_email', 'transporter_id']);

        // if ($custIpPort->transporter)
        //     $custIpPort->transporter->makeHidden(['transporter_address', 'transporter_contact_no', 'transporter_key', 'transporter_email']);

        // if ($custIpPort->register_by)
        //     $custIpPort->register_by->makeHidden(['username_email', 'transporter_id', 'contact_no', 'user_role', 'email_verified_at', 'first_login']);

        // if ($custIpPort->updated_by)
        //     $custIpPort->updated_by->makeHidden(['username_email', 'transporter_id', 'contact_no', 'user_role', 'email_verified_at', 'first_login']);

        return $custIpPort;
    }
}
