<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;



/*
|--------------------------------------------------------------------------
| API Routes — Sales-Spy
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api automatically by Laravel.
| We add /v1 here so every endpoint becomes /api/v1/something.
|
*/

Route::prefix('v1')->group(function () {
    // api for getting users details
    Route::get('/user', function (Request $request) {
        return $request->user();
    })->middleware('auth:sanctum');
    // Health check — no auth required
    Route::get('/health', function () {
        return response()->json([
            'success' => true,
            'message' => 'Sales-Spy API v1 is live',
            'data' => [
                'version' => '1.0.0',
                'environment' => app()->environment(),
            ],
        ]);
    });
});
