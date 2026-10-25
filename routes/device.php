<?php

use App\Http\Controllers\DeviceApiController;
use Illuminate\Support\Facades\Route;

// No session and no CSRF. The ESP32 cannot send a cookie or a CSRF token.
Route::post('/api/device/heartbeat', [DeviceApiController::class, 'heartbeat']);
Route::get('/api/device/state', [DeviceApiController::class, 'state']);
Route::get('/api/device/firmware/bin', [DeviceApiController::class, 'firmwareBin']);
Route::get('/api/device/firmware/md5', [DeviceApiController::class, 'firmwareMd5']);
