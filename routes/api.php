<?php

use App\Http\Controllers\UserController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\VendorController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GpsController;
use App\Http\Controllers\TransporterController;
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

//Vendor
Route::post('vendor/create', [VendorController::class, 'create']);
//User
Route::post('user/register', [UserController::class, 'register']);

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'user'
], function(){
    Route::post('/list', [UserController::class, 'list']);
    Route::get('/userById/{id}', [UserController::class, 'userById']);
    Route::put('/update/{id}', [UserController::class, 'update']);
    Route::put('/updatePassword/{id}', [UserController::class, 'updatePassword']);
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
    Route::post('/list', [VehicleController::class, 'list']);
    Route::get('/vehicleById/{id}', [VehicleController::class, 'vehicleById']);
    Route::post('/export', [VehicleController::class, 'vehicleExport']);
    Route::get('/provisioning/export', [VehicleController::class, 'provisioningExport']);
    Route::get('/unregistered/export', [VehicleController::class, 'unregisteredExport']);
    Route::put('/update/{id}', [VehicleController::class, 'update']);
    Route::put('/massUpdate', [VehicleController::class, 'massUpdate']);
    Route::delete('/delete/{id}', [VehicleController::class, 'delete']);
});

//This will catch GET request to /api/register but  PUT,DELETE, OPTIONS etc. fails
Route::fallback(function () {
    $response = new ApiResponse();
    return $response->ErrorResponse('Unauthenticated', 401);
});
