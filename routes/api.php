<?php

use App\Http\Controllers\UserController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\VendorController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GpsController;

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

Route::post('/login', [UserController::class, 'login']);
Route::post('/position', [GpsController::class, 'sendGPS']);

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'user'
], function(){
    Route::post('/register', [UserController::class, 'register']);
    Route::get('/list', [UserController::class, 'list']);
    Route::get('/userById/{id}', [UserController::class, 'userById']);
    Route::put('/update/{id}', [UserController::class, 'update']);
    Route::put('/updatePassword/{id}', [UserController::class, 'updatePassword']);
    Route::post('/logout', [UserController::class, 'logout']);
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'vendor'
], function(){
    Route::post('/create', [VendorController::class, 'create']);
    Route::get('/list', [VendorController::class, 'list']);
    Route::get('/vendorById/{id}', [VendorController::class, 'vendorById']);
    Route::put('/update/{id}', [VendorController::class, 'update']);
    Route::delete('/delete/{id}', [VendorController::class, 'delete']);
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'vehicle'
], function(){
    Route::post('/create', [VehicleController::class, 'create']);
    Route::get('/list', [VehicleController::class, 'list']);
    Route::get('/vehicleById/{id}', [VehicleController::class, 'vehicleById']);
    Route::put('/update/{id}', [VehicleController::class, 'update']);
    Route::put('/massUpdate', [VehicleController::class, 'massUpdate']);
    Route::delete('/delete/{id}', [VehicleController::class, 'delete']);
});
