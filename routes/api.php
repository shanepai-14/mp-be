<?php

use App\Http\Controllers\CurrentCustomerController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerIpPortController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\VendorController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GpsController;
use App\Http\Controllers\TransporterController;
use App\Http\Controllers\VehicleAssignmentsController;
use App\Http\Response\ApiResponse;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });

Route::post('/login', [UserController::class, 'login'])->name('login');
Route::post('/logout', [UserController::class, 'logout'])->middleware('auth:api');
Route::post('/position', [GpsController::class, 'sendGPS']);
Route::post('/check-server', [GpsController::class, 'checkServer']);
Route::post('/transporter/create-with-account', [TransporterController::class, 'publicCreate']);
Route::post('/user/publicRegister', [UserController::class, 'publicRegister']);

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'user'
], function(){
    Route::post('/create', [UserController::class, 'register']);
    Route::post('/list', [UserController::class, 'list']);
    Route::get('/userById/{id}', [UserController::class, 'userById']);
    Route::put('/update/{id}', [UserController::class, 'update']);
    Route::put('/updatePassword/{id}', [UserController::class, 'updatePassword']);
    Route::put('/resetPassword/{id}', [UserController::class, 'resetPassword']);
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'transporter'  // Transporter === Vendor
], function(){
    Route::get('/list', [TransporterController::class, 'list']);
    Route::get('/transporterById/{id}', [TransporterController::class, 'transporterById']);
    Route::put('/update/{id}', [TransporterController::class, 'update']);
    Route::delete('/delete/{id}', [TransporterController::class, 'delete']);
    Route::post('/create', [TransporterController::class, 'create']);
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'vehicle'
], function(){
    Route::post('/create', [VehicleController::class, 'create']);
    Route::post('/create-complete-info', [VehicleController::class, 'createCompleteData']);
    Route::post('/list', [VehicleController::class, 'list']);
    Route::get('/vehicleById/{id}', [VehicleController::class, 'vehicleById']);
    Route::post('/export', [VehicleController::class, 'vehicleExport']);
    Route::get('/provisioning/export', [VehicleController::class, 'provisioningExport']);
    Route::get('/unregistered/export', [VehicleController::class, 'unregisteredExport']);
    Route::put('/update/{id}', [VehicleController::class, 'update']);
    Route::put('/massUpdate', [VehicleController::class, 'massUpdate']);
    Route::delete('/delete/{id}', [VehicleController::class, 'delete']);
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'customer'  // Transporter === Vendor
], function(){
    Route::post('/create', [CustomerController::class, 'create']);
    Route::get('/customerById/{id}', [CustomerController::class, 'customerById']);
    Route::post('/list', [CustomerController::class, 'list']);
    Route::put('/update/{id}', [CustomerController::class, 'update']);
    Route::delete('/delete/{id}', [CustomerController::class, 'delete']);

    // For Customer-IP-Port
    Route::post('/ip-port/create', [CustomerIpPortController::class, 'create']);
    Route::get('/ip-port/ipPortById/{id}', [CustomerIpPortController::class, 'ipPortById']);
    Route::post('/ip-port/list', [CustomerIpPortController::class, 'list']);
    Route::put('/ip-port/update/{id}', [CustomerIpPortController::class, 'update']);
    Route::delete('/ip-port/delete/{id}', [CustomerIpPortController::class, 'delete']);
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'current'
], function(){
    Route::post('/create', [CurrentCustomerController::class, 'create']);
    Route::post('/list', [CurrentCustomerController::class, 'list']);
    Route::get('/currentCustById/{id}', [CurrentCustomerController::class, 'currentCustById']);
    Route::put('/update/{id}', [CurrentCustomerController::class, 'update']);
    Route::delete('/delete/{id}', [CurrentCustomerController::class, 'delete']);
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'assignment'
], function(){
    Route::post('/create', [VehicleAssignmentsController::class, 'create']);
    Route::post('/list', [VehicleAssignmentsController::class, 'list']);
    Route::get('/assignmentById/{id}', [VehicleAssignmentsController::class, 'assignmentById']);
    Route::put('/update/{id}', [VehicleAssignmentsController::class, 'update']);
    Route::put('/update-assign-customer/{id}', [VehicleAssignmentsController::class, 'updateAssignmentCustomer']);
    Route::delete('/delete/{id}', [VehicleAssignmentsController::class, 'delete']);
    Route::put('/approve/{id}', [VehicleAssignmentsController::class, 'approve']);
    Route::put('/reject/{id}', [VehicleAssignmentsController::class, 'reject']);
});

//This will catch GET request to /api/register but  PUT,DELETE, OPTIONS etc. fails
Route::fallback(function () {
    $response = new ApiResponse();
    return $response->ErrorResponse('Unauthenticated', 401);
});
