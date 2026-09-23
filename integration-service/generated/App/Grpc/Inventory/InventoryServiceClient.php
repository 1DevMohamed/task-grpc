<?php
# Generated gRPC client stub for InventoryService.
# source: proto/stock.proto

namespace App\Grpc\Inventory;

/**
 * gRPC client for the InventoryService.
 *
 * Provides CheckStock and SetAbsoluteStock RPC methods
 * for outbound calls from the Integration Service.
 */
class InventoryServiceClient extends \Grpc\BaseStub
{
    /**
     * Check stock availability for a product.
     *
     * @param CheckStockRequest $argument
     * @param array $metadata
     * @param array $options
     * @return \Grpc\UnaryCall
     */
    public function CheckStock(
        CheckStockRequest $argument,
        array $metadata = [],
        array $options = [],
    ): \Grpc\UnaryCall {
        return $this->_simpleRequest(
            '/inventory.InventoryService/CheckStock',
            $argument,
            [CheckStockResponse::class, 'decode'],
            $metadata,
            $options,
        );
    }

    /**
     * Set absolute stock quantity for a product.
     *
     * @param SetStockRequest $argument
     * @param array $metadata
     * @param array $options
     * @return \Grpc\UnaryCall
     */
    public function SetAbsoluteStock(
        SetStockRequest $argument,
        array $metadata = [],
        array $options = [],
    ): \Grpc\UnaryCall {
        return $this->_simpleRequest(
            '/inventory.InventoryService/SetAbsoluteStock',
            $argument,
            [SetStockResponse::class, 'decode'],
            $metadata,
            $options,
        );
    }
}
