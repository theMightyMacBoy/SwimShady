<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\DeviceController as AdminDeviceController;
use App\Http\Controllers\Admin\FirmwareController;
use App\Http\Controllers\Admin\IpController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UiApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/history', [DashboardController::class, 'history'])->name('history');
    Route::get('/account', [AccountController::class, 'edit'])->name('account');
    Route::post('/account/password', [AccountController::class, 'update'])->name('account.password');

    Route::get('/api/ui/state', [UiApiController::class, 'state'])->name('ui.state');
    Route::post('/api/ui/command', [UiApiController::class, 'command'])->name('ui.command');
    Route::get('/api/ui/history', [UiApiController::class, 'history'])->name('ui.history');
});

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/devices', [AdminDeviceController::class, 'index'])->name('devices.index');
    Route::post('/devices', [AdminDeviceController::class, 'store'])->name('devices.store');
    Route::post('/devices/{device}/token', [AdminDeviceController::class, 'regenerate'])->name('devices.token');
    Route::post('/devices/{device}/channel', [AdminDeviceController::class, 'channel'])->name('devices.channel');
    Route::post('/devices/{device}/delete', [AdminDeviceController::class, 'destroy'])->name('devices.destroy');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::post('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::post('/users/{user}/delete', [UserController::class, 'destroy'])->name('users.destroy');

    Route::get('/firmware', [FirmwareController::class, 'index'])->name('firmware.index');
    Route::post('/firmware', [FirmwareController::class, 'store'])->name('firmware.store');
    Route::post('/firmware/{firmware}/delete', [FirmwareController::class, 'destroy'])->name('firmware.destroy');

    Route::get('/admin/ips', [IpController::class, 'index'])->name('ips.index');
    Route::post('/admin/ips/rules', [IpController::class, 'store'])->name('ips.store');
    Route::post('/admin/ips/rules/{rule}/delete', [IpController::class, 'destroy'])->name('ips.destroy');
});
