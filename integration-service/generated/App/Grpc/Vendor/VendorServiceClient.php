<?php
# Generated gRPC client stub for VendorService.
# source: proto/price.proto

namespace App\Grpc\Vendor;

/**
 * gRPC client for the VendorService.
 *
 * Provides UpdatePrice and ResolveIds RPC methods
 * for outbound calls from the Integration Service.
 */
class VendorServiceClient extends \Grpc\BaseStub
{
    /**
     * Update the price for a vendor product variant.
     *
     * @param UpdatePriceRequest $argument
     * @param array $metadata
     * @param array $options
     * @return \Grpc\UnaryCall
     */
    public function UpdatePrice(
        UpdatePriceRequest $argument,
        array $metadata = [],
        array $options = [],
    ): \Grpc\UnaryCall {
        return $this->_simpleRequest(
            '/vendor.VendorService/UpdatePrice',
            $argument,
            [UpdatePriceResponse::class, 'decode'],
            $metadata,
            $options,
        );
    }

    /**
     * Resolve vendor_code + variant_sku to internal IDs.
     *
     * @param ResolveIdsRequest $argument
     * @param array $metadata
     * @param array $options
     * @return \Grpc\UnaryCall
     */
    public function ResolveIds(
        ResolveIdsRequest $argument,
        array $metadata = [],
        array $options = [],
    ): \Grpc\UnaryCall {
        return $this->_simpleRequest(
            '/vendor.VendorService/ResolveIds',
            $argument,
            [ResolveIdsResponse::class, 'decode'],
            $metadata,
            $options,
        );
    }
}
