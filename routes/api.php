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

Route::get('/health', fn () => ApiResponse::success([
    'service'          => 'messaging-service',
    'status'           => 'healthy',
    'timestamp'        => now()->toIso8601String(),
    'version'          => config('app.version', '1.0.0'),
    'environment'      => app()->environment(),
    'queue_connection' => config('queue.default'),
]));

Route::middleware('jwt.admin')->group(function () {
    Route::post('/deliveries', [DeliveryController::class, 'store']);
    Route::get('/deliveries/{uuid}', [DeliveryController::class, 'show']);
    Route::post('/deliveries/{uuid}/retry', [DeliveryController::class, 'retry']);
});
