<?php

use App\Http\Controllers\UserController;
use App\Http\Controllers\VendorController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'user'
], function(){
    Route::post('/register', [UserController::class, 'register']);
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'vendor'
], function(){
    Route::post('/create', [VendorController::class, 'create']);
});
