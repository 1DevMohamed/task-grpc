<?php

use App\Http\Controllers\HealthCheckController;
use Illuminate\Support\Facades\Route;

// Health check endpoints
Route::get('/health/live', [HealthCheckController::class, 'liveness']);
Route::get('/health/ready', [HealthCheckController::class, 'readiness']);