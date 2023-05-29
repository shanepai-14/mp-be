<?php

namespace App\Traits\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

use App\Models\User;
use App\Http\Response\ApiResponse;
use App\Mail\UserAccount;
use App\Models\Vendor;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

trait Register
{
    /**
     * 	@param $request
     */
    public function register(Request $request)
    {
        $response = new ApiResponse();

        $this->validateInput($request);
        $emailExist = User::where('username_email', $request->username_email)->exists();

        if ($emailExist)
            return $response->ErrorResponse('Email address already exist!', 409);

        else {
            if (!Vendor::find($request->vendor_id))
                return $response->ErrorResponse('Vendor id does not exist!', 404);

            $isUserExist = User::where('first_name', $request->first_name)
                ->where('last_name', $request->last_name)
                ->where('vendor_id', $request->vendor_id)
                ->where('user_role', $request->user_role)
                ->exists();

            if ($isUserExist)
                return $response->ErrorResponse('User already exist!', 409);

            else {
                // Temporarily Disable Password generation
                // Generate random password
                // $generatedPwd = bin2hex(random_bytes(5));

                $user = User::create([
                    'username_email' => $request->username_email,
                    // 'password' => Hash::make($generatedPwd),     // Temporarily Disable Password generation
                    'password' => Hash::make($request->password),   // Temporary Password for testing only
                    'first_name' => $request->first_name,
                    'last_name' => $request->last_name,
                    'vendor_id' => $request->vendor_id,
                    'contact_no' => $request->contact_no,
                    'user_role' => $request->user_role,
                ]);

                if ($user) {
                    // Temporarily Disable Emailling of Password Generated
                    // Mail::to($request->username_email)->send(new UserAccount($generatedPwd));
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
    private function validateInput($request)
    {
        return Validator::make($request->all(), [
            // 'username_email' => 'required|email|unique:username_email',
            'username_email' => 'email:rfc,dns',
            'password' => ['required', Password::min(6)],
        ])->validate();
    }

}
