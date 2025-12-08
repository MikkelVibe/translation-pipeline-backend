<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['message' => 'Translation Pipeline API']);
});

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return response()->json(['message' => 'Dashboard']);
    })->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
