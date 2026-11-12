<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\Integrations;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('citizen.locale')->group(function (): void {
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

/*
| Department integration API — authenticated by a per-department API key, not
| a citizen token. Documented publicly at /developers; keep the OpenAPI spec in
| public/openapi/integrations-v1.json in step with these routes.
*/
Route::prefix('integrations/v1')->middleware(['throttle:integrations', 'department.key'])->group(function (): void {
    Route::get('/department', [Integrations\DepartmentController::class, 'show']);
    Route::get('/categories', [Integrations\CategoryController::class, 'index']);
    Route::get('/reports', [Integrations\ReportController::class, 'index']);
    Route::get('/reports/{reportId}', [Integrations\ReportController::class, 'show'])->whereNumber('reportId');
    Route::post('/reports/{reportId}/updates', [Integrations\ReportController::class, 'storeUpdate'])
        ->whereNumber('reportId')
        ->middleware('department.key:write');
});
