<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Health / probe routes
|--------------------------------------------------------------------------
|
| Registered in bootstrap/app.php with NO middleware group on purpose.
| The `web` group would start a session, and SESSION_DRIVER=database means
| every probe would hit Postgres — so a database outage would fail the
| liveness check and get a healthy container restarted in a loop.
|
*/

Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');
