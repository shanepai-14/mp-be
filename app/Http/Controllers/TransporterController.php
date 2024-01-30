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
     *     tags={"Transporter"},
     *     path="/transporter/create-with-account",
     *     summary="Create Transporter and User Account",
     *     operationId="CreateTransporterPublic",
     *     @OA\RequestBody(
     *         description="Transporter Information",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="transporter_name",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="transporter_address",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="transporter_contact_no",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="transporter_email",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="username_email",
     *                     description="Email for transporter's account username",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="password",
     *                     description="Password for transporter's account",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="full_name",
     *                     description="Full name of transporter's user account",
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
     *                 example={"transporter_name": "transporter", "transporter_address": "Singapore", 
     *                          "transporter_contact_no": "+123123", "transporter_email": "transporter@sample.com",
     *                          "username_email": "username@username.com", "password": "samplepassword", "full_name": "Juan Dela Cruz", "contact_no": "+222222", "user_role": "0"}
     *             )
     *         )
     *     ),
     
     *     @OA\Response(
     *         response=200,
     *         description="Transporter is successfully registered",
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
     *          description="Transporter already exist!",
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
        $isUserExist = User::where('full_name', $request->first_name)
            ->where('transporter_id', $request->transporter_id)
            ->where('user_role', $request->user_role)
            ->exists();

        if ($isUserExist)
            return response('User already exist!', 409);


        // If passed the exist checking then proceed with the registration
        $createTransporter = $this->create($request);
        $response = json_decode(json_encode($createTransporter), true)['original'];

        if ($response['status'] === 200) {
            $userCreate = new UserController();

            $request['transporter_id'] = $response['data']['transporter']['id'];
            $createUser = $userCreate->register($request);
            return json_decode(json_encode($createUser), true)['original'];
        } 
        
        else if ($response['status'] === 409)
            return response('Transporter already exist!', 409);

        return response('Server Error', 500);
    }

     /**
     * @OA\Post(
     *     tags={"Transporter"},
     *     path="/transporter/create",
     *     summary="Create Transporter",
     *     operationId="CreateTransporter",
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         description="Transporter Information",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="transporter_name",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="transporter_address",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="transporter_contact_no",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="transporter_code",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="transporter_email",
     *                     type="string"
     *                 ),
     *                 example={"transporter_name": "transporter", "transporter_address": "Singapore", 
     *                          "transporter_contact_no": "+123123", "transporter_code": "VE", "transporter_email": "transporter@sample.com"}
     *             )
     *         )
     *     ),
     
     *     @OA\Response(
     *         response=200,
     *         description="Transporter is successfully registered",
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
     *          description="Transporter already exist!",
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
        $isTransporterExist = Transporter::where('transporter_name', $request->transporter_name)->exists();

        if ($isTransporterExist)
            return $response->ErrorResponse('Transporter already exist!', 409);

        else {
            $newTransporter = Transporter::create([
                'transporter_name' => $request->transporter_name,
                'transporter_address' => $request->transporter_address,
                'transporter_contact_no' => $request->transporter_contact_no,
                'transporter_code' => $request->transporter_code,
                'transporter_email' => $request->transporter_email,
                'transporter_key' => Str::uuid()->toString()
            ]);

            if ($newTransporter) {
                $responseData = ['transporter' => $newTransporter];
                return $response->SuccessResponse('Transporter is successfully registered', $responseData);
            }

            return $response->ErrorResponse('Server Error', 500);
        }
    }

    /**
     * @OA\Get(
     *     tags={"Transporter"},
     *     path="/transporter/list",
     *     summary="Get list of transporters",
     *     operationId="GetTransporterList",
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
     *     tags={"Transporter"},
     *     path="/transporter/transporterById/{id}",
     *     summary="Get transporter by transporter id",
     *     operationId="GetTransporterById",
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
     *         description="Transporter not found"
     *     ),
     * )
     */
    public function transporterById($id)
    {
        $transporter = Transporter::find($id);

        if ($transporter) return $transporter;

        $response = new ApiResponse();
        return $response->ErrorResponse('Transporter not found!', 404);
    }

    /**
     * @OA\Put(
     *     tags={"Transporter"},
     *     path="/transporter/update/{id}",
     *     summary="Updated Transporter",
     *     description="Update Transporter information.",
     *     operationId="UpdateTransporter",
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
     *         description="Updated Transporter object",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="id",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="transporter_name",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="transporter_address",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="transporter_contact_no",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="transporter_code",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="transporter_email",
     *                     type="string"
     *                 ),
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Transporter is successfully updated!"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Transporter not found"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Transporter Id does not matched!"
     *     )
     * )
     */
    public function update($id, Request $request)
    {
        $response = new ApiResponse();

        if ($id == $request->id) {
            $transporter = Transporter::find($id);

            if ($transporter) {
                $transporter->update($request->all());
                return $response->SuccessResponse('Transporter is successfully updated!', $transporter);
            }

            return $response->ErrorResponse('Transporter not found!', 404);
        }

        return $response->ErrorResponse('Transporter Id does not matched!', 400);
    }

    /**
     * @OA\Delete(
     *     tags={"Transporter"},
     *     path="/transporter/delete/{id}",
     *     summary="Delete transporter by transporter id",
     *     operationId="DeleteTransporter",
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
     *         description="Transporter is successfully deleted!",
     *         @OA\JsonContent(ref="#/components/schemas/Transporter")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Transporter does not exist!"
     *     ),
     * )
     */
    public function delete($id)
    {
        $response = new ApiResponse();
        $transporter = Transporter::find($id);

        if ($transporter) {
            $transporter->delete();
            return $response->SuccessResponse('Transporter is successfully deleted!', $transporter);
        }

        return $response->ErrorResponse('Transporter does not exist!', 404);
    }
}
