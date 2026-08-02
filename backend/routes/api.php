<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'service' => 'usgar-api', 'time' => now()->toIso8601String()]);
});
