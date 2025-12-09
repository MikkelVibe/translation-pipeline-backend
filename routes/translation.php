<?php

use App\Http\Controllers\Translation\TranslationController;
use Illuminate\Support\Facades\Route;

Route::post('/translate', [TranslationController::class, 'publish']);