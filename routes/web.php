<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CitizenController;
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

    Route::get('/dashboard', fn () => View::make('panel.coming-soon', ['title' => 'Dashboard']))->name('dashboard');

    // Placeholder sections — sidebar navigation only, functionality not yet built.
    $comingSoon = [
        'reports' => 'Reports',
        'analytics' => 'Analytics',
        'categories' => 'Categories',
        'departments' => 'Departments',
        'staff' => 'Staff Accounts',
        'activity-log' => 'Activity Log',
    ];

    foreach ($comingSoon as $slug => $title) {
        Route::get("/{$slug}", fn () => View::make('panel.coming-soon', ['title' => $title]))
            ->name("{$slug}.index");
    }
});
