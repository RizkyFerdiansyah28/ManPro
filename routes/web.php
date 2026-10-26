<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\CameraController;
use App\Http\Controllers\Admin\ResidentController as AdminResidentController;
use App\Http\Controllers\Admin\ResidentOperationsController;
use App\Http\Controllers\Admin\RoomController;
use App\Http\Controllers\Admin\ServiceClassController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Family\ResidentController as FamilyResidentController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Monitoring\MonitoringDashboardController;
use App\Http\Controllers\Monitoring\MonitoringRecordsController;
use App\Http\Controllers\Monitoring\OpenIncidentController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'redirect'])->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::middleware('can:viewMonitoring')->prefix('monitoring')->name('monitoring.')->group(function () {
        Route::get('/records', MonitoringRecordsController::class)->name('records');
        Route::get('/open-incidents', OpenIncidentController::class)->name('open-incidents');
    });
    Route::get('/dashboard', [HomeController::class, 'redirect'])->name('dashboard');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
        Route::get('/camera-room-options', [CameraController::class, 'roomOptions'])->name('camera-room-options');
        Route::get('/cameras', [CameraController::class, 'index'])->name('cameras.index');
        Route::get('/cameras/create', [CameraController::class, 'create'])->name('cameras.create');
        Route::post('/cameras', [CameraController::class, 'store'])->name('cameras.store');
        Route::get('/cameras/{camera}', [CameraController::class, 'show'])->name('cameras.show');
        Route::get('/cameras/{camera}/edit', [CameraController::class, 'edit'])->name('cameras.edit');
        Route::patch('/cameras/{camera}', [CameraController::class, 'update'])->name('cameras.update');
        Route::post('/cameras/{camera}/activate', [CameraController::class, 'activate'])->name('cameras.activate');
        Route::post('/cameras/{camera}/deactivate', [CameraController::class, 'deactivate'])->name('cameras.deactivate');
        Route::get('/service-classes', [ServiceClassController::class, 'index'])->name('service-classes.index');
        Route::get('/service-classes/{serviceClass}', [ServiceClassController::class, 'show'])->name('service-classes.show');
        Route::get('/service-classes/{serviceClass}/edit', [ServiceClassController::class, 'edit'])->name('service-classes.edit');
        Route::patch('/service-classes/{serviceClass}', [ServiceClassController::class, 'update'])->name('service-classes.update');
        Route::post('/service-classes/{serviceClass}/activate', [ServiceClassController::class, 'activate'])->name('service-classes.activate');
        Route::post('/service-classes/{serviceClass}/deactivate', [ServiceClassController::class, 'deactivate'])->name('service-classes.deactivate');
        Route::get('/rooms', [RoomController::class, 'index'])->name('rooms.index');
        Route::get('/rooms/create', [RoomController::class, 'create'])->name('rooms.create');
        Route::post('/rooms', [RoomController::class, 'store'])->name('rooms.store');
        Route::get('/rooms/{room}', [RoomController::class, 'show'])->name('rooms.show');
        Route::get('/rooms/{room}/edit', [RoomController::class, 'edit'])->name('rooms.edit');
        Route::patch('/rooms/{room}', [RoomController::class, 'update'])->name('rooms.update');
        Route::post('/rooms/{room}/activate', [RoomController::class, 'activate'])->name('rooms.activate');
        Route::post('/rooms/{room}/deactivate', [RoomController::class, 'deactivate'])->name('rooms.deactivate');
        Route::get('/family-options', [AdminResidentController::class, 'familyOptions'])->name('family-options');
        Route::get('/residents', [AdminResidentController::class, 'index'])->name('residents.index');
        Route::get('/residents/create', [AdminResidentController::class, 'create'])->name('residents.create');
        Route::prefix('residents/{elderly}')->name('residents.')->scopeBindings()->group(function () {
            Route::get('/operations', [ResidentOperationsController::class, 'index'])->name('operations');
            Route::get('/enrollments/create', [ResidentOperationsController::class, 'enrollmentForm'])->name('enrollments.create');
            Route::post('/enrollments', [ResidentOperationsController::class, 'enroll'])->name('enrollments.store');
            Route::get('/stays/create', [ResidentOperationsController::class, 'stayForm'])->name('stays.create');
            Route::post('/stays', [ResidentOperationsController::class, 'start'])->name('stays.store');
            Route::post('/stays/{stay}/close', [ResidentOperationsController::class, 'closeStay'])->name('stays.close');
            Route::post('/enrollments/{enrollment}/close', [ResidentOperationsController::class, 'closeEnrollment'])->name('enrollments.close');
        });
        Route::post('/residents', [AdminResidentController::class, 'store'])->name('residents.store');
        Route::get('/residents/{elderly}', [AdminResidentController::class, 'show'])->name('residents.show');
        Route::get('/residents/{elderly}/edit', [AdminResidentController::class, 'edit'])->name('residents.edit');
        Route::patch('/residents/{elderly}', [AdminResidentController::class, 'update'])->name('residents.update');
        Route::post('/residents/{elderly}/family', [AdminResidentController::class, 'reassignFamily'])->name('residents.family');
        Route::post('/residents/{elderly}/activate', [AdminResidentController::class, 'activate'])->name('residents.activate');
        Route::post('/residents/{elderly}/deactivate', [AdminResidentController::class, 'deactivate'])->name('residents.deactivate');
        Route::get('/', [MonitoringDashboardController::class, 'admin'])->middleware('can:viewMonitoring')->name('home');
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

    Route::get('/perawat', [MonitoringDashboardController::class, 'caregiver'])->middleware(['role:perawat', 'can:viewMonitoring'])->name('perawat.home');
    Route::get('/keluarga', [HomeController::class, 'show'])->middleware('role:keluarga')->name('keluarga.home');
    Route::prefix('keluarga/residents')->name('keluarga.residents.')->middleware('role:keluarga')->group(function () {
        Route::get('/', [FamilyResidentController::class, 'index'])->name('index');
        Route::get('/{elderly}', [FamilyResidentController::class, 'show'])->name('show');
    });
});
