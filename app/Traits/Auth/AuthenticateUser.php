<?php

namespace App\Traits\Auth;

use App\Http\Response\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

trait AuthenticateUser
{
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