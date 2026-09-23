<?php

namespace App\Handlers;

use App\Integration\Contracts\EventHandlerInterface;
use App\Integration\DTOs\EventEnvelope;
use App\Integration\Exceptions\PermanentFailureException;
use App\Services\VendorGrpcClientService;

/**
 * Handles product.price.updated events.
 *
 * Orchestration only — all pricing business logic is owned by Vendor Service.
 *
 * Flow:
 * 1. Validate payload contains required fields
 * 2. Call Vendor Service gRPC UpdatePrice with idempotency_key
 * 3. Return success/failure to the processing pipeline
 */
readonly class UpdatePriceHandler implements EventHandlerInterface
{
    public function __construct(
        private VendorGrpcClientService $vendor,
    ) {}

    public static function supportedEventTypes(): array
    {
        return ['product.price.updated'];
    }

    public function handle(EventEnvelope $envelope): void
    {
        $payload = $envelope->payload;

        // Validate required payload fields
        $this->validatePayload($payload);

        // Call Vendor Service — all business logic lives there
        $this->vendor->updatePrice(
            vendorCode:     $payload['vendor_code'],
            variantSku:     $payload['variant_sku'],
            price:          (float) $payload['price'],
            correlationId:  $envelope->correlationId,
            idempotencyKey: $envelope->eventId,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws PermanentFailureException if required fields are missing
     */
    private function validatePayload(array $payload): void
    {
        $missing = [];

        if (empty($payload['vendor_code'])) {
            $missing[] = 'vendor_code';
        }

        if (empty($payload['variant_sku'])) {
            $missing[] = 'variant_sku';
        }

        if (! isset($payload['price']) || ! is_numeric($payload['price'])) {
            $missing[] = 'price';
        }

        if (! empty($missing)) {
            throw new PermanentFailureException(
                "UpdatePriceHandler: missing or invalid payload fields: " . implode(', ', $missing),
                errorCategory: 'validation_error',
            );
        }

        if ((float) $payload['price'] < 0) {
            throw new PermanentFailureException(
                "UpdatePriceHandler: price must be >= 0, got {$payload['price']}",
                errorCategory: 'validation_error',
            );
        }
    }
}