<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);

        Route::get('/categories', [CategoryController::class, 'index']);

        Route::get('/reports/mine', [ReportController::class, 'mine']);
        Route::get('/reports', [ReportController::class, 'index']);
        Route::post('/reports', [ReportController::class, 'store']);
        Route::get('/reports/{report}', [ReportController::class, 'show']);
        Route::post('/reports/{report}/upvote', [ReportController::class, 'upvote']);
        Route::delete('/reports/{report}/upvote', [ReportController::class, 'removeUpvote']);

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/devices', [DeviceController::class, 'store']);
    });
});
