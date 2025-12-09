<?php

use App\Http\Controllers\Translation\TranslationController;
use Illuminate\Support\Facades\Route;

Route::post(uri: '/translate', action: [TranslationController::class, 'publish']);

# Hvis translations skal være authenticated, kan man bruge samme middleware som i de andre route filer
# ... Men indtil videre tænker jeg at det er fint at holde den public til testing

# Route::middleware('auth:sanctum')->group(function () {
#     Route::post('translate', [TranslationController::class, 'publish']);
# });