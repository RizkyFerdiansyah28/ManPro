<?php

use App\Http\Controllers\Billing\PaymentController;
use Illuminate\Support\Facades\Route;

// Registered as web routes; CSRF/session middleware remains intact. Admission defaults off.
Route::middleware(['auth', 'auth.session'])->name('payment.')->group(function () {
    Route::prefix('keluarga/billing/invoices')->middleware('role:keluarga')->name('family.')->group(function () {
        Route::get('/', [PaymentController::class, 'index'])->middleware('throttle:payment-read')->name('index');
        Route::get('/{invoice}', [PaymentController::class, 'showInvoice'])->whereUuid('invoice')->middleware('throttle:payment-read')->name('show');
        Route::get('/{invoice}/payment-context', [PaymentController::class, 'context'])->whereUuid('invoice')->middleware('throttle:payment-context')->name('context');
        Route::post('/{invoice}/payments', [PaymentController::class, 'submit'])->whereUuid('invoice')->middleware('throttle:payment-submit')->name('submit');
    });
    Route::prefix('admin/billing')->middleware('role:admin')->name('admin.')->group(function () {
        Route::get('/payments', [PaymentController::class, 'index'])->middleware('throttle:payment-read')->name('index');
        Route::prefix('invoices/{invoice}/payments/{payment}')->whereUuid(['invoice', 'payment'])->group(function () {
            Route::get('/', [PaymentController::class, 'showPayment'])->middleware('throttle:payment-read')->name('show');
            Route::get('/review-context', [PaymentController::class, 'context'])->middleware('throttle:payment-context')->name('context');
            Route::post('/verify', [PaymentController::class, 'verify'])->middleware('throttle:payment-review')->name('verify');
            Route::post('/reject', [PaymentController::class, 'reject'])->middleware('throttle:payment-review')->name('reject');
        });
    });
    Route::get('/billing/payment-commands/recovery-context', [PaymentController::class, 'recoveryContext'])->middleware('throttle:payment-context')->name('recovery-context');
    Route::post('/billing/payment-commands/reconcile', [PaymentController::class, 'reconcile'])->middleware('throttle:payment-reconcile')->name('reconcile');
    Route::get('/billing/invoices/{invoice}/payments/{payment}/proof', [PaymentController::class, 'proof'])->whereUuid(['invoice', 'payment'])->middleware('throttle:payment-proof')->name('proof');
});
