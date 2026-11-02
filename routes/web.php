<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CitizenController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentApiKeyController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DeveloperDocsController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StaffController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::get('/developers', [DeveloperDocsController::class, 'index'])->name('developers');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

Route::middleware(['auth', 'role:admin,staff'])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/citizens', [CitizenController::class, 'index'])->name('citizens.index');
    Route::patch('/citizens/{citizen}', [CitizenController::class, 'update'])->name('citizens.update');
    Route::post('/citizens/{citizen}/approve', [CitizenController::class, 'approve'])->name('citizens.approve');
    Route::post('/citizens/{citizen}/reject', [CitizenController::class, 'reject'])->name('citizens.reject');
    Route::patch('/citizens/{citizen}/toggle-active', [CitizenController::class, 'toggleActive'])->name('citizens.toggle-active');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::patch('/reports/{report}', [ReportController::class, 'update'])->name('reports.update');

    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
});

Route::middleware(['auth', 'role:admin'])->group(function (): void {
    Route::delete('/citizens/{citizen}', [CitizenController::class, 'destroy'])->name('citizens.destroy');

    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::patch('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
    Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
    Route::patch('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
    Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy');
    Route::post('/departments/{department}/api-keys', [DepartmentApiKeyController::class, 'store'])->name('departments.api-keys.store');
    Route::delete('/departments/{department}/api-keys/{apiKey}', [DepartmentApiKeyController::class, 'destroy'])
        ->scopeBindings()
        ->name('departments.api-keys.destroy');

    Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
    Route::patch('/staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
    Route::patch('/staff/{staff}/toggle-active', [StaffController::class, 'toggleActive'])->name('staff.toggle-active');
    Route::delete('/staff/{staff}', [StaffController::class, 'destroy'])->name('staff.destroy');

    Route::get('/activity-log', [ActivityLogController::class, 'index'])->name('activity-log.index');
});
