<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'redirect'])->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::get('/dashboard', [HomeController::class, 'redirect'])->name('dashboard');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
        Route::get('/', [HomeController::class, 'show'])->name('home');
        Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::get('/accounts/create', [AccountController::class, 'create'])->name('accounts.create');
        Route::post('/accounts/perawat', [AccountController::class, 'storeCaregiver'])->name('accounts.store-perawat');
        Route::post('/accounts/keluarga', [AccountController::class, 'storeFamily'])->name('accounts.store-keluarga');
        Route::post('/accounts/{user}/activate', [AccountController::class, 'activate'])->name('accounts.activate');
        Route::post('/accounts/{user}/deactivate', [AccountController::class, 'deactivate'])->name('accounts.deactivate');
        Route::get('/accounts/{user}/password', [AccountController::class, 'editPassword'])->name('accounts.password.edit');
        Route::post('/accounts/{user}/password', [AccountController::class, 'resetPassword'])
            ->middleware('throttle:admin-password-reset')->name('accounts.password.update');
    });

    Route::get('/perawat', [HomeController::class, 'show'])->middleware('role:perawat')->name('perawat.home');
    Route::get('/keluarga', [HomeController::class, 'show'])->middleware('role:keluarga')->name('keluarga.home');
});
