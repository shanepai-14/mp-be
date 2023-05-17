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

    public function list()
    {
        return User::all();
    }

    public function userById($id)
    {
       return User::find($id);
    }

    // public function updateUser($id, Request $request)
    // {
    //     $response = new ApiResponse();

    //     if ($id == $request->id) {
    //         $user = User::find($id);

    //         if (!is_null($user)) {
    //             $isExisted = Sitio::where('sitio_name', '=', $request->sitio_name)
    //                 ->orWhere('sitio_sname', '=', $request->sitio_sname)
    //                 ->count();
    //             if (!$isExisted) {
    //                 $user->update($request->all());
    //                 return $user;
    //             } else {
    //                 return new JsonResponse([
    //                     'message' => $request->sitio_name . ' or ' . $request->sitio_sname . ' is already added.'
    //                 ], 409);
    //             }
    //         } else return new JsonResponse([], 204);
    //     } else {
    //         $response->ErrorResponse('User Id does not matched!');
    //     }
    // }
}
