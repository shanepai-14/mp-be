<?php

use App\Http\Controllers\CurrentCustomerController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerIpPortController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\VendorController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GpsController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\TransporterController;
use App\Http\Controllers\VehicleAssignmentsController;
use App\Http\Controllers\PooledGPSSocketController;
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

Route::post('/login', [UserController::class, 'login'])->name('login')->middleware('throttle:60,1');
Route::post('/logout', [UserController::class, 'logout'])->middleware(['auth:api', 'throttle:60,1']);
Route::post('/position', [GpsController::class, 'sendGPS'])->middleware('throttle:position');
Route::post('/check-server', [GpsController::class, 'checkServer']);
Route::post('/vendor/create-with-account', [TransporterController::class, 'publicCreate'])->middleware('throttle:60,1');
Route::post('/user/publicRegister', [UserController::class, 'publicRegister'])->middleware('throttle:60,1');;

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'user'
], function(){
    Route::post('/create', [UserController::class, 'register'])->middleware('throttle:60,1');
    Route::post('/list', [UserController::class, 'list'])->middleware('throttle:200,1');
    Route::get('/userById/{id}', [UserController::class, 'userById'])->middleware('throttle:60,1');
    Route::put('/update/{id}', [UserController::class, 'update'])->middleware('throttle:60,1');
    Route::put('/updatePassword/{id}', [UserController::class, 'updatePassword'])->middleware('throttle:60,1');
    Route::put('/resetPassword/{id}', [UserController::class, 'resetPassword'])->middleware('throttle:60,1');
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'vendor'  // Transporter === Vendor
], function(){
    Route::get('/list', [TransporterController::class, 'list'])->middleware('throttle:200,1');;
    Route::get('/vendorById/{id}', [TransporterController::class, 'transporterById'])->middleware('throttle:60,1');
    Route::put('/update/{id}', [TransporterController::class, 'update'])->middleware('throttle:60,1');
    // Route::delete('/delete/{id}', [TransporterController::class, 'delete'])->middleware('throttle:60,1');
    Route::post('/create', [TransporterController::class, 'create'])->middleware('throttle:60,1');
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'vehicle'
], function(){
    Route::post('/create', [VehicleController::class, 'create'])->middleware('throttle:60,1');
    Route::post('/create-complete-info', [VehicleController::class, 'createCompleteData'])->middleware('throttle:60,1');
    Route::post('/list', [VehicleController::class, 'list'])->middleware('throttle:200,1');
    Route::get('/vehicleById/{id}', [VehicleController::class, 'vehicleById'])->middleware('throttle:200,1');
    Route::post('/export', [VehicleController::class, 'vehicleExport'])->middleware('throttle:60,1');
    Route::post('/provisioning/export', [VehicleController::class, 'provisioningExport'])->middleware('throttle:60,1');
    Route::post('/unregistered/export', [VehicleController::class, 'unregisteredExport'])->middleware('throttle:60,1');
    Route::put('/update/{id}', [VehicleController::class, 'update'])->middleware('throttle:60,1');
    Route::put('/massUpdate', [VehicleController::class, 'massUpdate'])->middleware('throttle:60,1');
    // Route::delete('/delete/{id}', [VehicleController::class, 'delete'])->middleware('throttle:60,1');
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'customer'  // Transporter === Vendor
], function(){
    Route::post('/create', [CustomerController::class, 'create'])->middleware('throttle:60,1');
    Route::get('/customerById/{id}', [CustomerController::class, 'customerById'])->middleware('throttle:60,1');
    Route::post('/list', [CustomerController::class, 'list'])->middleware('throttle:200,1');;
    Route::put('/update/{id}', [CustomerController::class, 'update'])->middleware('throttle:60,1');
    // Route::delete('/delete/{id}', [CustomerController::class, 'delete'])->middleware('throttle:60,1');

    // For Customer-IP-Port
    Route::post('/ip-port/create', [CustomerIpPortController::class, 'create'])->middleware('throttle:60,1');
    Route::get('/ip-port/ipPortById/{id}', [CustomerIpPortController::class, 'ipPortById'])->middleware('throttle:60,1');
    Route::post('/ip-port/list', [CustomerIpPortController::class, 'list'])->middleware('throttle:200,1');;
    Route::put('/ip-port/update/{id}', [CustomerIpPortController::class, 'update'])->middleware('throttle:60,1');
    Route::delete('/ip-port/delete/{id}', [CustomerIpPortController::class, 'delete'])->middleware('throttle:60,1');
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'current'
], function(){
    Route::post('/create', [CurrentCustomerController::class, 'create'])->middleware('throttle:60,1');
    Route::post('/list', [CurrentCustomerController::class, 'list'])->middleware('throttle:200,1');;
    Route::get('/currentCustById/{id}', [CurrentCustomerController::class, 'currentCustById'])->middleware('throttle:60,1');
    Route::put('/update/{id}', [CurrentCustomerController::class, 'update'])->middleware('throttle:60,1');
    // Route::delete('/delete/{id}', [CurrentCustomerController::class, 'delete'])->middleware('throttle:60,1');
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'assignment'
], function(){
    Route::post('/create', [VehicleAssignmentsController::class, 'create'])->middleware('throttle:60,1');
    Route::post('/list', [VehicleAssignmentsController::class, 'list'])->middleware('throttle:200,1');;
    Route::get('/assignmentById/{id}', [VehicleAssignmentsController::class, 'assignmentById'])->middleware('throttle:60,1');
    Route::put('/update/{id}', [VehicleAssignmentsController::class, 'update'])->middleware('throttle:60,1');
    Route::put('/update-assign-customer/{id}', [VehicleAssignmentsController::class, 'updateAssignmentCustomer'])->middleware('throttle:60,1');
    // Route::delete('/delete/{id}', [VehicleAssignmentsController::class, 'delete'])->middleware('throttle:250,1');
    Route::put('/approve/{id}', [VehicleAssignmentsController::class, 'approve'])->middleware('throttle:250,1');
    Route::put('/reject/{id}', [VehicleAssignmentsController::class, 'reject'])->middleware('throttle:250,1');
});

Route::prefix('gps')->group(function () {

Route::get('/socket-pool/stats', [GpsController::class, 'getSocketPoolStats']);
Route::post('/test-gps-connection', [GpsController::class, 'testGpsConnection']);
Route::delete('/socket-pool/connection', [GpsController::class, 'closeSocketPoolConnection']);
Route::post('/batch-gps', [GpsController::class, 'batchSendGPS']);

});



//This will catch GET request to /api/register but  PUT,DELETE, OPTIONS etc. fails
Route::fallback(function () {
    $response = new ApiResponse();
    return $response->ErrorResponse('Unauthenticated', 401);
});
