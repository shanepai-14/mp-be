<?php

namespace App\Traits\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use App\Http\Response;
use App\Http\Response\ApiResponse;

trait Register
{
    /**
     * 	@param $request
     */
    public function register(Request $request)
    {
        $response = new ApiResponse();

        // $this->validateInput($request);
        $emailExist = User::where('username_email', $request->username_email)->exists();

        if ($emailExist)
            return $response->ErrorResponse('Email address already exist!', 409);

        else {
            $isUserExist = User::where('first_name', $request->first_name)
                ->where('last_name', $request->last_name)
                ->where('vendor_id', $request->vendor_id)
                ->where('user_role', $request->user_role)
                ->exists();

            if ($isUserExist)
                return $response->ErrorResponse('User already exist!', 409);

            else {
                $user = $this->createUser($request);

                if ($user) {
                    $responseData = ['user' => $user];
                    return $response->SuccessResponse('User successfully registered', $responseData);
                }

                return $response->ErrorResponse('Server Error', 500);
            }
        }
    }




    /**
     * 	@param $request
     */
    public function createUser($request)
    {
        return User::create([
            'username_email' => $request->username_email,
            'password' => Hash::make($request->password),
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'vendor_id' => $request->vendor_id,
            'contact_no' => $request->contact_no,
            'user_role' => $request->user_role,
        ]);
    }



    /**
     * 	@param $request
     */
    // private function validateInput($request)
    // {
    //     return Validator::make($request->all(), [
    //         'username_email' => 'required|email|unique:username_email',
    //         // 'password' => ['required', 'min:8', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*(_|[^\w])).+$/'],
    //     ])->validate();
    // }

}
