<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'Translation Pipeline API',
        'version' => '1.0.0',
    ]);
});

Route::get('/ping', function () {
    return response()->json([
        'message' => 'pong',
        'timestamp' => now()->toIso8601String(),
    ]);
});

require __DIR__.'/auth.php';
require __DIR__.'/settings.php';
