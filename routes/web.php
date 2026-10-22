<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\ResidentController as AdminResidentController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Family\ResidentController as FamilyResidentController;
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
        Route::get('/family-options', [AdminResidentController::class, 'familyOptions'])->name('family-options');
        Route::get('/residents', [AdminResidentController::class, 'index'])->name('residents.index');
        Route::get('/residents/create', [AdminResidentController::class, 'create'])->name('residents.create');
        Route::post('/residents', [AdminResidentController::class, 'store'])->name('residents.store');
        Route::get('/residents/{elderly}', [AdminResidentController::class, 'show'])->name('residents.show');
        Route::get('/residents/{elderly}/edit', [AdminResidentController::class, 'edit'])->name('residents.edit');
        Route::patch('/residents/{elderly}', [AdminResidentController::class, 'update'])->name('residents.update');
        Route::post('/residents/{elderly}/family', [AdminResidentController::class, 'reassignFamily'])->name('residents.family');
        Route::post('/residents/{elderly}/activate', [AdminResidentController::class, 'activate'])->name('residents.activate');
        Route::post('/residents/{elderly}/deactivate', [AdminResidentController::class, 'deactivate'])->name('residents.deactivate');
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
    Route::prefix('keluarga/residents')->name('keluarga.residents.')->middleware('role:keluarga')->group(function () {
        Route::get('/', [FamilyResidentController::class, 'index'])->name('index');
        Route::get('/{elderly}', [FamilyResidentController::class, 'show'])->name('show');
    });
});
