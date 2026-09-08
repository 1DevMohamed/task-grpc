<?php

namespace App\Services;

use App\Grpc\Inventory\InventoryServiceClient;
use App\Grpc\Inventory\SetStockRequest;
use Grpc\ChannelCredentials;
use Log;
use RuntimeException;
use const Grpc\STATUS_OK;

class InventoryGrpcClientService
{
    private InventoryServiceClient $stub;

    public function __construct()
    {
        $this->stub = new InventoryServiceClient(
            config('services.inventory.grpc_host', 'inventory-svc:9001'),
            ['credentials' => ChannelCredentials::createInsecure()]
        );
    }

    public function setAbsoluteStock(int $productId, int $quantity): void
    {
        $request = new SetStockRequest();
        $request->setProductId($productId);
        $request->setQuantity($quantity);

        [$response, $status] = $this->stub->SetAbsoluteStock($request, [], [
            'timeout' => 5000000
        ])->wait();

        if ($status->code !== STATUS_OK) {
            throw new RuntimeException(
                "InventoryService SetAbsoluteStock failed [{$status->code}]: {$status->details}"
            );
        }

        Log::info('SetAbsoluteStock success', [
            'product_id' => $productId,
            'quantity' => $quantity,
            'message' => $response->getMessage(),
        ]);
    }
}