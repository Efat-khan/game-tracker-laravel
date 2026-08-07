<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckinController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\StationController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['status' => 'ok']);

/*
|--------------------------------------------------------------------------
| Public — no account, rate limited per IP
|--------------------------------------------------------------------------
*/

Route::get('/stations/{id}/qrcode', [StationController::class, 'qrcode'])
    ->whereNumber('id');

Route::get('/stations/{id}/public', [StationController::class, 'publicShow'])
    ->whereNumber('id')
    ->middleware('throttle:public_station');

// optional.auth so signed-in staff bypass the QR signature check (§7.2).
Route::post('/checkin/{station}', [CheckinController::class, 'store'])
    ->whereNumber('station')
    ->middleware(['optional.auth', 'throttle:checkin']);

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

/*
|--------------------------------------------------------------------------
| Inside a cafe — every route below is scoped by ResolveCafeContext
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:cafetrack', 'cafe'])->group(function () {

    // Stations — staff may look, admins may change.
    Route::get('/stations', [StationController::class, 'index']);
    Route::post('/stations/{id}/maintenance', [StationController::class, 'maintenance'])->whereNumber('id');

    Route::middleware('admin')->group(function () {
        Route::post('/stations', [StationController::class, 'store']);
        Route::patch('/stations/{id}', [StationController::class, 'update'])->whereNumber('id');
        Route::delete('/stations/{id}', [StationController::class, 'destroy'])->whereNumber('id');
    });

    // Sessions
    Route::get('/sessions/active', [SessionController::class, 'active']);
    Route::get('/sessions', [SessionController::class, 'index']);
    Route::post('/sessions/{id}/cancel', [SessionController::class, 'cancel'])->whereNumber('id');
    Route::post('/checkout/{session}', [SessionController::class, 'checkout'])->whereNumber('session');
});
