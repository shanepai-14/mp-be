<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\User;
use App\Traits\Auth\AuthenticateUser;
use App\Traits\Auth\Register;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use OpenApi\Annotations as OA;

/**
 * Class User.
 */
class UserController extends Controller
{
    use Register, AuthenticateUser;

    /**
     * @OA\Post(
     *     path="/user/list",
     *     tags={"User"},
     *     summary="Get list of registered users",
     *     operationId="UserList",
     *     security={{"bearerAuth": {}}},
     * @OA\RequestBody(
     *         description="Vendor Id - NOTE: If vendor_id object is omitted then all users will be return.",
     *         required=false,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="vendor_id",
     *                     type="integer"
     *                 ),
     *                 example={"vendor_id": 0}
     *             )
     *         )
     *     ),
     * @OA\Response(
     *         response=200,
     *         description="successful operation"
     *     ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthenticated",
     *      ),
     *      @OA\Response(
     *          response=403,
     *          description="Forbidden"
     *      )
     * )
     */
    public function list(Request $request)
    {
        if ($request->vendor_id)
            return User::with(['vendor'])->where('vendor_id', $request->vendor_id)->get();

        return User::with(['vendor'])->get();
    }

    /**
     * @OA\Get(
     *     tags={"User"},
     *     path="/user/userById/{id}",
     *     summary="Get user by user id",
     *     operationId="getUserById",
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
     *         description="successful operation",
     *         @OA\JsonContent(ref="#/components/schemas/User")
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid user id supplied"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found"
     *     ),
     * )
     */
    public function userById($id)
    {
        $user = User::with(['vendor'])->find($id);

        if ($user) return $user;

        $response = new ApiResponse();
        return $response->ErrorResponse('User not found!', 404);
    }

    /**
     * @OA\Put(
     *     tags={"User"},
     *     path="/user/update/{id}",
     *     summary="Updated user",
     *     description="Update user information.",
     *     operationId="updateUser",
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
     *         description="Updated user object",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="id",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="username_email",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="full_name",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="vendor_id",
     *                     type="integer"
     *                 ),
     *                  @OA\Property(
     *                     property="contact_no",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="user_role",
     *                     type="integer"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="User is successfully updated!"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid user id supplied"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found"
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="User Id does not matched!"
     *     )
     * )
     */
    public function update($id, Request $request)
    {
        $response = new ApiResponse();

        if ($id == $request->id) {
            $user = User::find($id);
            
            if ($user) {
                $user->update($request->all());
                $userData = $this->userById($user->id);

                return $response->SuccessResponse('User is successfully updated!', $userData);
            }

            return $response->ErrorResponse('User not found!', 404);
        }

        return $response->ErrorResponse('User Id does not matched!', 409);
    }

    /**
     * @OA\Put(
     *     tags={"User"},
     *     path="/user/updatePassword/{id}",
     *     summary="Updated user password",
     *     description="Update user password.",
     *     operationId="updatePassword",
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
     *     @OA\RequestBody(
     *         description="Old and new password",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="oldPassword",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="newPassword",
     *                     type="string"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Password is successfully updated!"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found"
     *     ),
     *     @OA\Response(
     *         response=449,
     *         description="New password is the same with the previous password used."
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Incorrect old password"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update password!"
     *     )
     * )
     */
    public function updatePassword($id, Request $request)
    {
        $response = new ApiResponse();

        $request->validate([
            'oldPassword' => 'required',
            'newPassword' => 'required'
        ]);

        if ($request->oldPassword == $request->newPassword)
            return $response->ErrorResponse('New password is the same with the previous password used.', 409);

        $user = User::find($id);

        if ($user) {
            if (!Hash::check($request->oldPassword, $user->password))
                return $response->ErrorResponse('Incorrect old password', 409);

            $user->password = Hash::make($request->newPassword);
            if ($user->save()) {
                $user->first_login = 0;
                $user->save();
                return $response->SuccessResponse('Password is successfully updated!', $user);
            }

            return $response->ErrorResponse('Failed to update password!', 500);
        }

        return $response->ErrorResponse('User not found!', 404);
    }

    /**
     * @OA\Post(
     *     tags={"User"},
     *     path="/user/logout",
     *     summary="Logs out current logged in user session",
     *     description="Logs out current logged in user session",
     *     operationId="logoutUser",
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response="default",
     *         description="successful operation"
     *     )
     * )
     */
    public function logout()
    {
        $user = Auth::user()->token();
        $user->revoke();
        return 'logged out';
    }
}
