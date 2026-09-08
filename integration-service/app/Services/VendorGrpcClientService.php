<?php

namespace App\Services;

use App\Grpc\Vendor\ResolveIdsRequest;
use App\Grpc\Vendor\ResolveIdsResponse;
use App\Grpc\Vendor\UpdatePriceRequest;
use App\Grpc\Vendor\VendorServiceClient;
use Grpc\ChannelCredentials;
use RuntimeException;
use const Grpc\STATUS_OK;

class VendorGrpcClientService
{
    private VendorServiceClient $stub;

    public function __construct()
    {
        $this->stub = new VendorServiceClient(
            config('services.vendor.grpc_host', 'vendor-svc:50054'),
            ['credentials' => ChannelCredentials::createInsecure()]
        );
    }

    public function updatePrice(string $vendorCode, string $variantSku, float $price): void
    {
        $request = new UpdatePriceRequest();

        $request->setVendorCode($vendorCode);
        $request->setVariantSku($variantSku);

        $request->setPrice($price);

        [$response, $status] = $this->stub->UpdatePrice($request)->wait();

        if ($status->code !== STATUS_OK) {
            throw new RuntimeException("VendorService UpdatePrice failed: {$status->details}");
        }
    }

    public function resolveIds(string $vendorCode, string $variantSku): ResolveIdsResponse
    {
        $request = new ResolveIdsRequest();

        $request->setVendorCode($vendorCode);
        $request->setVariantSku($variantSku);

        [$response, $status] = $this->stub->ResolveIds($request)->wait();

        if ($status->code !== STATUS_OK) {
            throw new RuntimeException("VendorService ResolveIds failed: {$status->details}");
        }

        return $response;
    }

}