<?php
use App\Http\Controllers\Admin\V1\CategoryController;
use App\Http\Controllers\Admin\V1\BrandController;

Route::apiResource('categories', CategoryController::class);
Route::apiResource('brands', BrandController::class);
