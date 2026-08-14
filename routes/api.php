<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Admin\AuthController;

Route::prefix('admin')->group(function () {

    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function() {
    Route::post('/admin/logout', [AuthController::class, 'logout']);
});


Route::prefix('admin/v1')->name('admin.v1.')->middleware('auth:sanctum')->group(base_path('routes/admin/v1.php'));
