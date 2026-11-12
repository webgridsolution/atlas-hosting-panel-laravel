<?php

use App\Http\Controllers\Api\V1\LicenseController;
use App\Http\Controllers\Api\V1\ReleaseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Licensing API v1 — consumed by installed panels and the installer
|--------------------------------------------------------------------------
| Public by necessity (a server has no account here yet). Abuse is controlled
| by rate limiting, uniform errors, security events and signed responses.
*/
Route::prefix('v1')->middleware('throttle:license')->group(function () {
    Route::post('license/activate', [LicenseController::class, 'activate']);
    Route::post('license/heartbeat', [LicenseController::class, 'heartbeat']);
    Route::post('license/deactivate', [LicenseController::class, 'deactivate']);
    Route::get('releases/latest', [ReleaseController::class, 'latest']);
});

Route::get('v1/health', fn () => response()->json(['ok' => true, 'time' => now()->toIso8601String()]));
