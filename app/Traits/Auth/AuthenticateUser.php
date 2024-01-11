<?php

namespace App\Traits\Auth;

use App\Http\Response\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OpenApi\Annotations as OA;

/**
 * Authenticate User Login.
 */
trait AuthenticateUser
{

    /**
     * @OA\Post(
     *     path="/login",
     *     tags={"Auth"},
     *     summary="Logs user into system",
     *     operationId="loginUser",
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
     *                     type="string"
     *                 ),
     *                 example={"username_email": "wloc@example.com", "password": ""}
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="OK"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid username/password supplied"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error"
     *     )
     * )
     */
    public function login(Request $request)
    {
        if ($this->attempt($request)) {
            $user = Auth::guard()->user();

            // $info = User::where('username_email', $request->username_email)->first();
            $token = $user->createToken('token')->accessToken;

            return $this->loginSuccessResponse([
                'user' => $user,
                // 'info' => $info,
                'type' => 'Bearer',
                'token' => $token
            ]);
        }
        return $this->loginErrorResponse();
    }

    public function attempt($request)
    {
        return Auth::guard()->attempt($this->credentials($request));
    }

    public function credentials($request)
    {
        return $request->only('username_email', 'password');
    }

    public function loginSuccessResponse($data)
    {
        $response = new ApiResponse();
        return $response->SuccessResponse('Ok', $data);
    }

    public function loginErrorResponse()
    {
        $response = new ApiResponse();
        return $response->ErrorResponse('Email or Password is incorrect!', 401);
    }
}
