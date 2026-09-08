<?php

namespace App\Handlers;

use App\Services\VendorGrpcClientService;

readonly class UpdatePriceHandler
{
    public function __construct(private readonly VendorGrpcClientService $vendor)
    {
    }

    public function handle(array $message): void
    {
        $this->vendor->updatePrice(
            vendorCode: $message['vendor_code'],
            variantSku: $message['variant_sku'],
            price: $message['price'],
        );
    }
}