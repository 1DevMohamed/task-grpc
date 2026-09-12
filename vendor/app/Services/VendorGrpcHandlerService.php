<?php

namespace App\Services;


use App\Grpc\Vendor\UpdatePriceRequest;
use App\Grpc\Vendor\UpdatePriceResponse;
use App\Grpc\Vendor\ResolveIdsRequest;
use App\Grpc\Vendor\ResolveIdsResponse;
use App\Grpc\Vendor\VendorServiceInterface;
use Spiral\RoadRunner\GRPC\ContextInterface;

class VendorGrpcHandlerService implements VendorServiceInterface
{
    private array $vendors = [
        'VENDOR-001' => ['id' => 1, 'name' => 'Acme Corp'],
        'VENDOR-002' => ['id' => 2, 'name' => 'Global Trade'],
    ];

    private array $variants = [
        'SKU-001' => ['product_id' => 101],
        'SKU-002' => ['product_id' => 102],
    ];

    public function UpdatePrice(
        ContextInterface   $ctx,
        UpdatePriceRequest $request
    ): UpdatePriceResponse {


        if (!isset($this->vendors[$request->getVendorCode()])) {
            $response = new UpdatePriceResponse();
            $response->setSuccess(false);
            $response->setMessage("Vendor not found: {$request->getVendorCode()}");
            return $response;
        }

        \Log::info('Price updated', [
            'vendor_code' => $request->getVendorCode(),
            'variant_sku' => $request->getVariantSku(),
            'price'       => $request->getPrice(),
        ]);

        $response = new UpdatePriceResponse();
        $response->setSuccess(true);
        $response->setMessage('Price updated');
        return $response;
    }

    public function ResolveIds(
        ContextInterface  $ctx,
        ResolveIdsRequest $request
    ): ResolveIdsResponse {
        $response = new ResolveIdsResponse();

        $vendor  = $this->vendors[$request->getVendorCode()] ?? null;
        $variant = $this->variants[$request->getVariantSku()] ?? null;

        if (!$vendor || !$variant) {
            throw new \RuntimeException("Cannot resolve IDs for {$request->getVendorCode()} / {$request->getVariantSku()}");
        }

        $response->setInternalProductId($variant['product_id']);
        $response->setInternalVendorId($vendor['id']);

        return $response;
    }
}