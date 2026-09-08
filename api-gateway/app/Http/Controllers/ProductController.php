<?php

namespace App\Http\Controllers;


use App\Events\updateStockEvent;
use App\Http\Requests\UpdateProductStockRequest;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function updateStock(UpdateProductStockRequest $request): JsonResponse
    {
        $dto = $request->toDto();

        // event(new updateStockEvent($dto->toArray()));

        return response()->json([
            'message' => 'Stock update published',
            'product' => $dto->toArray(),
        ]);
    }
}
