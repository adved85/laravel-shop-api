<?php
use App\Http\Controllers\Admin\V1\CategoryController;

Route::apiResource('categories', CategoryController::class);
