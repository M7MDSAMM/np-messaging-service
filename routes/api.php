<?php

use App\Http\Controllers\DeliveryController;
use App\Http\Responses\ApiResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Messaging Service — API Routes (v1)
|--------------------------------------------------------------------------
|
| Prefix: /api/v1
| All routes here are stateless and expect JSON.
|
*/

Route::get('/health', function () {
    return ApiResponse::success([
        'service' => config('app.name'),
        'status'  => 'ok',
        'time'    => now()->toIso8601String(),
    ], 'Service is healthy.');
});

Route::middleware('jwt.admin')->group(function () {
    Route::post('/deliveries', [DeliveryController::class, 'store']);
    Route::get('/deliveries/{uuid}', [DeliveryController::class, 'show']);
    Route::post('/deliveries/{uuid}/retry', [DeliveryController::class, 'retry']);
});
