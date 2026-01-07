<?php

use App\Http\Controllers\Api\PromptController;
use Illuminate\Support\Facades\Route;

Route::apiResource('prompts', PromptController::class);
