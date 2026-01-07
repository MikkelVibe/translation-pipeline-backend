<?php

use App\Http\Controllers\Api\JobController;
use Illuminate\Support\Facades\Route;

Route::prefix('jobs')->group(function () {
    Route::get('/', [JobController::class, 'index']);
    Route::post('/', [JobController::class, 'store']);
    Route::get('/{job}', [JobController::class, 'show']);
    Route::get('/{job}/items', [JobController::class, 'items']);
    Route::get('/{job}/errors', [JobController::class, 'errors']);
});
