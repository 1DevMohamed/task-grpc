<?php

namespace App\Http\Controllers;

use App\Events\OrderPlacedEvent;
use App\Services\InventoryGrpcClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function store(
        Request $request,
        InventoryGrpcClientService $inventory
    ): JsonResponse {
        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'customer_email' => ['required', 'email'],
        ]);

        $stock = $inventory->checkStock(
            $validated['product_id'],
            $validated['quantity']
        );

        $order = [
            'order_id'       => rand(1000, 9999),
            'product_id'     => $validated['product_id'],
            'quantity'       => $validated['quantity'],
            'customer_email' => $validated['customer_email'],
            'total'          => 99.99,
            'placed_at'      => now()->toISOString(),
        ];


        return response()->json([
            'product_id' => $validated['product_id'],
            'quantity' => $validated['quantity'],
            'inventory' => $stock,
        ]);
    }
}