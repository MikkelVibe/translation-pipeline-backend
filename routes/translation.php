<?php

use App\Http\Controllers\Translation\TranslationController;
use Illuminate\Support\Facades\Route;

Route::post('/translate/ids', [TranslationController::class, 'publishIds']);
Route::post('/translate/range', [TranslationController::class, 'publishRange']);

// Hvis translations skal være authenticated, kan man bruge samme middleware som i de andre route filer
// ... Men indtil videre tænker jeg at det er fint at holde den public til testing

// Route::middleware('auth:sanctum')->group(function () {
//     Route::post('/translate/ids', [TranslationController::class, 'publishIds']);
//     Route::post('/translate/range', [TranslationController::class, 'publishRange']);
// });
