<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\User;
use App\Traits\Auth\AuthenticateUser;
use App\Traits\Auth\Register;
use Illuminate\Http\Request;

use function PHPUnit\Framework\isNull;

class UserController extends Controller
{
    use Register, AuthenticateUser;

    public function list(Request $request)
    {
        if ($request->vendor_id)
            return User::where('vendor_id', $request->vendor_id)->get();

        return User::all();
    }

    public function userById($id)
    {
       $user = User::find($id);

       if($user) return $user;

       $response = new ApiResponse();
       return $response->ErrorResponse('User not found!', 404);
    }

    public function update($id, Request $request)
    {
        $response = new ApiResponse();

        if ($id == $request->id) {
            $user = User::find($id);

            if($user) 
            {
                $user->update($request->all());
                return $response->SuccessResponse('User is successfully updated!', $user); 
            }

            return $response->ErrorResponse('User not found!', 404);
        } 

        return $response->ErrorResponse('User Id does not matched!', 409);
    }
}
