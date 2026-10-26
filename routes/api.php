<?php

use App\Http\Controllers\Api\V1\AiIngestionController;
use App\Http\Middleware\AuthenticateAiSource;
use Illuminate\Support\Facades\Route;

Route::prefix('ai/v1')->middleware(AuthenticateAiSource::class)->group(function () {
    Route::post('fall-detections', [AiIngestionController::class, 'fall'])->name('ai.fall-detections');
    Route::post('gait-observations', [AiIngestionController::class, 'gait'])->name('ai.gait-observations');
});
