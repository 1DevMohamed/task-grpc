<?php

namespace App\Handlers;

use App\Services\InventoryGrpcClientService;
use App\Services\VendorGrpcClientService;

readonly class UpdateStockHandler
{
    public function __construct(
        private VendorGrpcClientService $vendor,
        private InventoryGrpcClientService $inventory,
    ) {
    }

    public function handle(array $message): void
    {

        $resolved = $this->vendor->resolveIds(
            vendorCode: $message['vendor_code'],
            variantSku: $message['variant_sku'],
        );

        $this->inventory->setAbsoluteStock(
            productId: $resolved->getInternalProductId(),
            quantity: $message['quantity'],
        );
    }
}