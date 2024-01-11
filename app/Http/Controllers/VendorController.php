<?php

namespace App\Http\Controllers;

use App\Http\Response\ApiResponse;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Str;



class VendorController extends Controller
{
    /**
     * @OA\Post(
     *     tags={"Vendor"},
     *     path="/vendor/create",
     *     summary="Create vendor",
     *     operationId="CreateVendor",
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         description="Vendor Information",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="vendor_name",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="vendor_address",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="vendor_contact_no",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="vendor_code",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="vendor_email",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="wl_ip",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="wl_port",
     *                     type="integer"
     *                 ),
     *                 example={"vendor_name": "Vendor", "vendor_address": "Singapore", 
     *                          "vendor_contact_no": "+123123", "vendor_code": "VE", "vendor_email": "vendor@sample.com",
     *                          "wl_ip": "111.111.111.111", "wl_port": 1111}
     *             )
     *         )
     *     ),
     
     *     @OA\Response(
     *         response=200,
     *         description="Vendor is successfully registered",
     *         @OA\JsonContent(ref="#/components/schemas/Vendor")
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
     *          description="Vendor already exist!",
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
        $isVendorExist = Vendor::where('vendor_code', $request->vendor_code)->exists();

        if ($isVendorExist)
            return $response->ErrorResponse('Vendor already exist!', 409);

        else {
            $newVendor = Vendor::create([
                'vendor_name' => $request->vendor_name,
                'vendor_address' => $request->vendor_address,
                'vendor_contact_no' => $request->vendor_contact_no,
                'vendor_code' => $request->vendor_code,
                'vendor_email' => $request->vendor_email,
                'vendor_key' => Str::uuid()->toString(),
                'wl_ip' => $request->wl_ip,
                'wl_port' => $request->wl_port
            ]);

            if ($newVendor) {
                $responseData = ['vendor' => $newVendor];
                return $response->SuccessResponse('Vendor is successfully registered', $responseData);
            }

            return $response->ErrorResponse('Server Error', 500);
        }
    }

    /**
     * @OA\Get(
     *     tags={"Vendor"},
     *     path="/vendor/list",
     *     summary="Get list of vendors",
     *     operationId="GetVendorList",
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="ok",
     *         @OA\JsonContent(ref="#/components/schemas/Vendor")
     *     ),
     *     @OA\Response(
     *          response=401,
     *          description="Unauthenticated",
     *      ),
     *     @OA\Response(
     *          response=500,
     *          description="Internal Server Error",
     *      ),
     * )
     */
    public function list()
    {
        return Vendor::all();
    }

    /**
     * @OA\Get(
     *     tags={"Vendor"},
     *     path="/vendor/vendorById/{id}",
     *     summary="Get vendor by vendor id",
     *     operationId="GetVendorById",
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
     *         @OA\JsonContent(ref="#/components/schemas/Vendor")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Vendor not found"
     *     ),
     * )
     */
    public function vendorById($id)
    {
        $vendor = Vendor::find($id);

        if ($vendor) return $vendor;

        $response = new ApiResponse();
        return $response->ErrorResponse('Vendor not found!', 404);
    }

    /**
     * @OA\Put(
     *     tags={"Vendor"},
     *     path="/vendor/update/{id}",
     *     summary="Updated vendor",
     *     description="Update vendor information.",
     *     operationId="UpdateVendor",
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
     *         description="Updated vendor object",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="id",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="vendor_name",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="vendor_address",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="vendor_contact_no",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="vendor_code",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="vendor_email",
     *                     type="string"
     *                 ),
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Vendor is successfully updated!"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Vendor not found"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Vendor Id does not matched!"
     *     )
     * )
     */
    public function update($id, Request $request)
    {
        $response = new ApiResponse();

        if ($id == $request->id) {
            $vendor = Vendor::find($id);

            if ($vendor) {
                $vendor->update($request->all());
                return $response->SuccessResponse('Vendor is successfully updated!', $vendor);
            }

            return $response->ErrorResponse('Vendor not found!', 404);
        }

        return $response->ErrorResponse('Vendor Id does not matched!', 400);
    }

    /**
     * @OA\Delete(
     *     tags={"Vendor"},
     *     path="/vendor/delete/{id}",
     *     summary="Delete vendor by vendor id",
     *     operationId="DeleteVendor",
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
     *         description="Vendor is successfully deleted!",
     *         @OA\JsonContent(ref="#/components/schemas/Vendor")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Vendor does not exist!"
     *     ),
     * )
     */
    public function delete($id)
    {
        $response = new ApiResponse();
        $vendor = Vendor::find($id);

        if ($vendor) {
            $vendor->delete();
            return $response->SuccessResponse('Vendor is successfully deleted!', $vendor);
        }

        return $response->ErrorResponse('Vendor does not exist!', 404);
    }
}
