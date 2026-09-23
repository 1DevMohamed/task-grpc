<?php

namespace App\Handlers;

use App\Integration\Contracts\EventHandlerInterface;
use App\Integration\DTOs\EventEnvelope;
use App\Integration\Exceptions\PermanentFailureException;
use App\Services\InventoryGrpcClientService;
use App\Services\VendorGrpcClientService;

/**
 * Handles product.stock.updated events.
 *
 * Orchestration only — business logic is split between Vendor and Inventory services.
 *
 * Two-phase flow:
 *   RPC #1: Vendor Service → resolve internal product/vendor IDs from vendor_code + variant_sku
 *   RPC #2: Inventory Service → set the absolute available stock using the resolved product ID
 *
 * Failure handling:
 * - If RPC #1 fails: event is retried (both RPCs are idempotent)
 * - If RPC #1 succeeds but RPC #2 fails: event is retried. RPC #1 is re-executed
 *   but is idempotent (ResolveIds is a read-only operation). RPC #2 is also
 *   idempotent because SetAbsoluteStock with the same idempotency_key is a no-op
 *   if already applied.
 *
 * This is NOT a distributed transaction — it's a retry-safe orchestration pattern.
 */
readonly class UpdateStockHandler implements EventHandlerInterface
{
    public function __construct(
        private VendorGrpcClientService    $vendor,
        private InventoryGrpcClientService $inventory,
    ) {}

    public static function supportedEventTypes(): array
    {
        return ['product.stock.updated'];
    }

    public function handle(EventEnvelope $envelope): void
    {
        $payload = $envelope->payload;

        // Validate required payload fields
        $this->validatePayload($payload);

        // ── RPC #1: Resolve internal IDs via Vendor Service ──
        $resolved = $this->vendor->resolveIds(
            vendorCode:     $payload['vendor_code'],
            variantSku:     $payload['variant_sku'],
            correlationId:  $envelope->correlationId,
            idempotencyKey: $envelope->eventId,
        );

        $internalProductId = $resolved->getInternalProductId();

        // ── RPC #2: Set stock via Inventory Service ──────────
        $this->inventory->setAbsoluteStock(
            productId:      (int) $internalProductId,
            quantity:        (int) $payload['quantity'],
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

        if (! isset($payload['quantity']) || ! is_numeric($payload['quantity'])) {
            $missing[] = 'quantity';
        }

        if (! empty($missing)) {
            throw new PermanentFailureException(
                "UpdateStockHandler: missing or invalid payload fields: " . implode(', ', $missing),
                errorCategory: 'validation_error',
            );
        }
    }
}