<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\JsonResponse;

Route::get(uri: '/', action: fn (): JsonResponse => response()->json(data: [
    'message' => 'Translation Pipeline API',
    'version' => '1.0.0',
]));

Route::get(uri: '/ping', action: fn (): JsonResponse => response()->json(data: [
    'message' => 'pong',
    'timestamp' => now()->toIso8601String(),
]));

require __DIR__.'/auth.php';
require __DIR__.'/settings.php';
require __DIR__.'/translation.php';
