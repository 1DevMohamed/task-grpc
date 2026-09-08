<?php

use App\Http\Controllers\ProductController;
use App\Http\Controllers\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


Route::post('/products/update-stock', [ProductController::class, 'updateStock']);

Route::post('/webhook/integration', [WebhookController::class, 'receive']);