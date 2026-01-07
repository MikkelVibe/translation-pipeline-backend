<?php

use App\Http\Controllers\Api\LanguageController;
use Illuminate\Support\Facades\Route;

Route::apiResource('languages', LanguageController::class)->except(['show']);
