<?php

namespace App\Traits\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

use App\Models\User;
use App\Models\FarmerProfile;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

trait Register
{

    /**
     * 	@param $request
     */
    public function register(Request $request)
    {
        // $this->validateInput($request);

        $isVendorExist = Vendor::where('vendor_code', $request->vendor_code)->exists();

        if ($isVendorExist)
            return new JsonResponse([
                'status' => true,
                'message' => 'Vendor already exist!'
            ], 409);

        else {
            $emailExist = User::where('username_email', $request->username_email)->exists();

            if ($emailExist)
                return new JsonResponse([
                    'status' => true,
                    'message' => 'Email address already exist!'
                ], 409);

            else {
                $isUserExist = User::where('username_email', $request->username_email)
                    ->where('first_name', $request->first_name)
                    ->where('last_name', $request->last_name)
                    ->where('vendor_id', $request->vendor_id)
                    ->where('user_role', 0)
                    ->exists();

                if ($isUserExist) {
                    return new JsonResponse([
                        'status' => true,
                        'message' => 'User already exist!'
                    ], 409);
                } else {
                    if ($vendor = $this->createVendor($request)) {
                        $user = $this->createUser($request, $vendor);
                        return $this->registerSuccessResponse($user, $vendor);
                    }

                    return $this->registerErrorResponse();
                }
            }
        }
    }




    /**
     * 	@param $request
     */
    public function createUser($request, $vendor)
    {
        return User::create([
            'username_email' => $request->username_email,
            'password' => Hash::make($request->password),
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'vendor_id' => $vendor->id,
            'contact_no' => $request->contact_no,
            'user_role' => $request->user_role,
        ]);
    }


    /**
     * 
     * @param $request
     * 
     */
    public function createVendor($request)
    {
        return Vendor::create([
            'vendor_name' => $request->vendor_name,
            'vendor_address' => $request->vendor_address,
            'vendor_contact_no' => $request->vendor_contact_no,
            'vendor_code' => $request->vendor_code,
            'vendor_key' => $request->vendor_key
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




    /**
     * @param $data
     * @return JsonResponse
     */
    private function registerSuccessResponse($user, $vendor): JsonResponse
    {
        return new JsonResponse([
            'status' => true,
            'message' => 'User and vendor successfully registered.',
            'data' => [
                'user' => $user,
                'vendor' => $vendor
            ]
        ], 200);
    }




    /**
     * @return JsonResponse
     */
    private function registerErrorResponse(): JsonResponse
    {
        return new JsonResponse([
            'status' => true,
            'message' => 'Server error.',
        ], 409);
    }
}
