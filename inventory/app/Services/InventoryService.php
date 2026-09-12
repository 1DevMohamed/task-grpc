<?php

namespace App\Services;

use App\Grpc\Inventory\CheckStockRequest;
use App\Grpc\Inventory\CheckStockResponse;
use App\Grpc\Inventory\SetStockRequest;
use App\Grpc\Inventory\SetStockResponse;
use App\Grpc\Inventory\InventoryServiceInterface;
use Spiral\RoadRunner\GRPC\ContextInterface;

class InventoryService implements InventoryServiceInterface
{
    private static array $stock = [
        1   => 100,
        2   => 50,
        3   => 20,
        101 => 200,
        102 => 150,
    ];

    public function CheckStock(
        ContextInterface  $ctx,
        CheckStockRequest $request
    ): CheckStockResponse {
        $productId         = $request->getProductId();
        $quantity          = $request->getQuantity();
        $availableQuantity = self::$stock[$productId] ?? 0;

        $response = new CheckStockResponse();
        $response->setAvailable($availableQuantity >= $quantity);
        $response->setAvailableQuantity($availableQuantity);

        return $response;
    }

    public function SetAbsoluteStock(
        ContextInterface $ctx,
        SetStockRequest  $request
    ): SetStockResponse {
        $productId = $request->getProductId();
        $quantity  = $request->getQuantity();

        self::$stock[$productId] = $quantity;

        \Log::info('Stock updated absolutely', [
            'product_id' => $productId,
            'quantity'   => $quantity,
        ]);

        $response = new SetStockResponse();
        $response->setSuccess(true);
        $response->setMessage("Stock for product {$productId} set to {$quantity}");

        return $response;
    }
}