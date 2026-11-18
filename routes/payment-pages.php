<?php

use App\Http\Controllers\Billing\PaymentPageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'auth.session', 'throttle:payment-read'])->name('payment.pages.')->group(function () {
    Route::middleware('role:keluarga')->prefix('keluarga/tagihan')->group(function () {
        Route::get('/', PaymentPageController::class)->name('family.index');
        Route::get('/{invoice}', PaymentPageController::class)->whereUuid('invoice')->name('family.show');
    });
    Route::middleware('role:admin')->prefix('admin/pembayaran')->group(function () {
        Route::get('/', PaymentPageController::class)->name('admin.index');
        Route::get('/{invoice}/{payment}', PaymentPageController::class)->whereUuid(['invoice', 'payment'])->name('admin.show');
    });
});
