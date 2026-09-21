<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CitizenController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

Route::middleware(['auth', 'role:admin'])->group(function (): void {
    Route::get('/citizens', [CitizenController::class, 'index'])->name('citizens.index');
    Route::patch('/citizens/{citizen}', [CitizenController::class, 'update'])->name('citizens.update');
    Route::post('/citizens/{citizen}/approve', [CitizenController::class, 'approve'])->name('citizens.approve');
    Route::post('/citizens/{citizen}/reject', [CitizenController::class, 'reject'])->name('citizens.reject');
    Route::patch('/citizens/{citizen}/toggle-active', [CitizenController::class, 'toggleActive'])->name('citizens.toggle-active');
    Route::delete('/citizens/{citizen}', [CitizenController::class, 'destroy'])->name('citizens.destroy');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::patch('/reports/{report}', [ReportController::class, 'update'])->name('reports.update');

    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::patch('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
    Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
    Route::patch('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
    Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy');

    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');

    // Placeholder sections — sidebar navigation only, functionality not yet built.
    $comingSoon = [
        'staff' => 'Staff Accounts',
        'activity-log' => 'Activity Log',
    ];

    foreach ($comingSoon as $slug => $title) {
        Route::get("/{$slug}", fn () => View::make('panel.coming-soon', ['title' => $title]))
            ->name("{$slug}.index");
    }
});
