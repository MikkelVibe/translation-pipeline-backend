<?php

use App\Http\Controllers\Api\SettingsController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get(uri: '/', action: fn (): JsonResponse => response()->json(data: [
    'message' => 'Translation Pipeline API',
    'version' => '1.0.0',
]));

Route::get(uri: '/ping', action: fn (): JsonResponse => response()->json(data: [
    'message' => 'pong',
    'timestamp' => now()->toIso8601String(),
]));

// Auth routes
require __DIR__.'/auth.php';

// Profile settings routes (existing)
require __DIR__.'/settings.php';

// Translation routes (existing)
require __DIR__.'/translation.php';

// New API routes
require __DIR__.'/jobs.php';
require __DIR__.'/prompts.php';
require __DIR__.'/languages.php';
require __DIR__.'/dashboard.php';

// App settings routes (no auth for now)
Route::get('/app-settings', [SettingsController::class, 'show']);
Route::put('/app-settings', [SettingsController::class, 'update']);
