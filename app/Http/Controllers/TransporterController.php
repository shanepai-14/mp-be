<?php

namespace App\Http\Controllers;

use App\Http\Response\ApiResponse;
use App\Models\Transporter;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Traits\Auth\Register;

class TransporterController extends Controller
{
    /**
     * @OA\Post(
     *     tags={"Vendor"},
     *     path="/vendor/create-with-account",
     *     summary="Create Vendor and User Account",
     *     operationId="CreateVendorPublic",
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
     *                     property="vendor_email",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="username_email",
     *                     description="Email for vendor's account username",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="password",
     *                     description="Password for vendor's account",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="full_name",
     *                     description="Full name of vendor's user account",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="contact_no",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="user_role",
     *                     type="integer"
     *                 ),
     *                 example={"vendor_name": "Vendor", "vendor_address": "Singapore",
     *                          "vendor_contact_no": "+123123", "vendor_email": "vendor@sample.com",
     *                          "username_email": "username@username.com", "password": "samplepassword", "full_name": "Juan Dela Cruz", "contact_no": "+222222", "user_role": "0"}
     *             )
     *         )
     *     ),

     *     @OA\Response(
     *         response=200,
     *         description="Vendor is successfully registered",
     *         @OA\JsonContent(ref="#/components/schemas/Transporter")
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
    public function publicCreate(Request $request)
    {
        // Check first if the email use for username already exist.
        $emailExist = User::where('username_email', $request->username_email)->exists();

        if ($emailExist)
            return response('Email address used as username already exist!', 409);


        // Check if user with the given fullname, transporterId and userRole already exist
        $isUserExist = User::where('full_name', $request->vendor_name)
            ->where('transporter_id', $request->vendor_id)
            ->where('user_role', $request->user_role)
            ->exists();

        if ($isUserExist)
            return response('User already exist!', 409);


        // If passed the exist checking then proceed with the registration
        $createTransporter = $this->create($request);
        $response = json_decode(json_encode($createTransporter), true)['original'];

        if ($response['status'] === 200) {
            $userCreate = new UserController();

            $request['vendor_id'] = $response['data']['transporter']['id'];
            $createUser = $userCreate->register($request);
            return json_decode(json_encode($createUser), true)['original'];
        }

        else if ($response['status'] === 409)
            return response('Vendor already exist!', 409);

        return response('Server Error', 500);
    }

     /**
     * @OA\Post(
     *     tags={"Vendor"},
     *     path="/vendor/create",
     *     summary="Create Vendor",
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
     *                 example={"vendor_name": "Vendor", "vendor_address": "Singapore",
     *                          "vendor_contact_no": "+123123", "vendor_code": "VE", "vendor_email": "vendor@sample.com"}
     *             )
     *         )
     *     ),

     *     @OA\Response(
     *         response=200,
     *         description="Vendor is successfully registered",
     *         @OA\JsonContent(ref="#/components/schemas/Transporter")
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
        // $isTransporterExist = Transporter::where('transporter_code', $request->transporter_code)->exists();
        $isTransporterExist = Transporter::where('transporter_name', $request->vendor_name)->exists();

        if ($isTransporterExist)
            return $response->ErrorResponse('Transporter already exist!', 409);

        else {
            $newTransporter = Transporter::create([
                'transporter_name' => $request->vendor_name,
                'transporter_address' => $request->vendor_address,
                'transporter_contact_no' => $request->vendor_contact_no,
                'transporter_code' => $request->vendor_code,
                'transporter_email' => $request->vendor_email,
                'transporter_key' => Str::uuid()->toString()
            ]);

            if ($newTransporter) {
                $responseData = ['transporter' => $newTransporter];
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
     *         @OA\JsonContent(ref="#/components/schemas/Transporter")
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
        return Transporter::all();
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
     *         @OA\JsonContent(ref="#/components/schemas/Transporter")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Vendor not found"
     *     ),
     * )
     */
    public function transporterById($id)
    {
        $transporter = Transporter::find($id);

        if ($transporter) return $transporter;

        $response = new ApiResponse();
        return $response->ErrorResponse('Vendor not found!', 404);
    }

    /**
     * @OA\Put(
     *     tags={"Vendor"},
     *     path="/vendor/update/{id}",
     *     summary="Updated Vendor",
     *     description="Update Vendor information.",
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
     *         description="Updated Vendor object",
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
            $transporter = Transporter::find($id);

            if ($transporter) {
                $transporter->update([
                    'transporter_name' => $request->vendor_name,
                    'transporter_address' => $request->vendor_address,
                    'transporter_contact_no' => $request->vendor_contact_no,
                    'transporter_code' => $request->vendor_code,
                    'transporter_email' => $request->vendor_email,
                ]);
                return $response->SuccessResponse('Vendor is successfully updated!', $transporter);
            }

            return $response->ErrorResponse('Vendor not found!', 404);
        }

        return $response->ErrorResponse('Vendor Id does not matched!', 400);
    }

    // /**
    //  * @OA\Delete(
    //  *     tags={"Vendor"},
    //  *     path="/vendor/delete/{id}",
    //  *     summary="Delete vendor by vendor id",
    //  *     operationId="DeleteVendor",
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
    //  *         description="Vendor is successfully deleted!",
    //  *         @OA\JsonContent(ref="#/components/schemas/Transporter")
    //  *     ),
    //  *     @OA\Response(
    //  *         response=404,
    //  *         description="Vendor does not exist!"
    //  *     ),
    //  * )
    //  */
    // public function delete($id)
    // {
    //     $response = new ApiResponse();
    //     $transporter = Transporter::find($id);

    //     if ($transporter) {
    //         $transporter->delete();
    //         return $response->SuccessResponse('Vendor is successfully deleted!', $transporter);
    //     }

    //     return $response->ErrorResponse('Vendor does not exist!', 404);
    // }
}
