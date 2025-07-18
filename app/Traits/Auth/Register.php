<?php

namespace App\Traits\Auth;

use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

use App\Models\User;
use App\Http\Response\ApiResponse;
use App\Mail\UserAccount;
use App\Models\Transporter;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;
use OpenApi\Annotations as OA;

/**
 * Create User Account.
 */
trait Register
{
    /**
     * @OA\Post(
     *     tags={"User"},
     *     path="/user/register",
     *     summary="Create user account",
     *     operationId="CreateUser",
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         description="User Credentials",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="username_email",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="password",
     *                     description="Password for transporter's account",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="full_name",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="transporter_id",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="contact_no",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="user_role",
     *                     type="integer"
     *                 ),
     *                 example={"username_email": "wloc@example.com", "password": "samplepassword",
     *                          "full_name": "Sample User", "transporter_id": "0",
     *                          "contact_no": "+123", "user_role": "0"}
     *             )
     *         )
     *     ),

     *     @OA\Response(
     *         response=200,
     *         description="OK",
     *         @OA\JsonContent(ref="#/components/schemas/User")
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
     *          description="User or Email address already exist!",
     *      ),
     *     @OA\Response(
     *          response=404,
     *          description="Transporter id does not exist!",
     *      ),
     *     @OA\Response(
     *          response=500,
     *          description="Internal Server Error",
     *      ),
     * )
     */
    public function register(Request $request)
    {
        $response = new ApiResponse();

        $this->validateInput($request);
        $emailExist = User::where('username_email', $request->username_email)->exists();

        if ($emailExist)
            return $response->ErrorResponse('Email address already exist!', 409);

        else {
            $isUserExist = User::where('full_name', $request->first_name)
                ->where('transporter_id', $request->vendor_id)
                ->where('user_role', $request->user_role)
                ->exists();

            if ($isUserExist)
                return $response->ErrorResponse('User already exist!', 409);

            else {
                // Generate random password
                // $generatedPwd = bin2hex(random_bytes(5));

                $user = User::create([
                    'username_email' => $request->username_email,
                    // 'password' => Hash::make($generatedPwd),     // Temporarily Disable Password generation
                    'password' => Hash::make($request->password),   // Temporary Password
                    'full_name' => $request->full_name,
                    'transporter_id' => $request->vendor_id,
                    'contact_no' => $request->contact_no,
                    'user_role' => $request->user_role,
                    'first_login' => $request->first_login ?? true
                ]);

                if ($user) {
                    // Emailling of Password Generated
                    // Mail::to($request->username_email)->send(new UserAccount($generatedPwd));

                    $userCtrl = new UserController();
                    $userData = $userCtrl->userById($user->id);

                    $responseData = ['user' => $userData];
                    return $response->SuccessResponse('User successfully registered', $responseData);
                }

                return $response->ErrorResponse('Server Error', 500);
            }
        }
    }

    /**
     * 	@param $request
     */
    private function validateInput($request)
    {
        return Validator::make($request->all(), [
            // 'username_email' => 'required|email|unique:username_email',
            'username_email' => 'email:rfc,dns',
            // 'password' => ['required', Password::min(6)->mixedCase()->numbers()],
        ])->validate();
    }
}
