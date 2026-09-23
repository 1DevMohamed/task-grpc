<?php

namespace App\Http\Controllers;

use App\Http\Requests\WebhookIntegrationRequest;
use App\Services\SqsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Throwable;

class WebhookController extends Controller
{
    public function __construct(private readonly SqsService $sqs) {}

    /**
     * Receive a webhook event and publish it to SQS as a standardized event envelope.
     *
     * Returns HTTP 202 Accepted — this means the event has been durably queued,
     * NOT that the business operation has completed.
     */
    public function receive(WebhookIntegrationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $eventId       = (string) Str::uuid();
        $correlationId = $request->header('X-Correlation-Id', (string) Str::uuid());
        $causationId   = $request->header('X-Causation-Id', $correlationId);

        // Determine event type from message_type
        $eventType = match ($validated['message_type']) {
            'price_update'  => 'product.price.updated',
            'stock_update'  => 'product.stock.updated',
            default         => $validated['message_type'],
        };

        // Determine aggregate type
        $aggregateType = match ($eventType) {
            'product.price.updated' => 'product_variant',
            'product.stock.updated' => 'product_variant',
            default                 => 'unknown',
        };

        // Build standardized event envelope
        $envelope = [
            'event_id'       => $eventId,
            'event_type'     => $eventType,
            'event_version'  => 1,
            'aggregate_type' => $aggregateType,
            'aggregate_id'   => $validated['variant_sku'],
            'merchant_id'    => $validated['merchant_id'] ?? '',
            'correlation_id' => $correlationId,
            'causation_id'   => $causationId,
            'occurred_at'    => now()->toIso8601String(),
            'source'         => 'api-gateway',
            'payload'        => [
                'vendor_code'  => $validated['vendor_code'],
                'variant_sku'  => $validated['variant_sku'],
                'quantity'     => $validated['quantity'] ?? null,
                'price'        => $validated['price'] ?? null,
            ],
        ];

        // Publish to SQS — if this fails, do NOT return fake success
        try {
            $this->sqs->publish('integration-inbound', $envelope);
        } catch (Throwable $e) {
            \Log::error('Failed to publish event to SQS', [
                'event_id'       => $eventId,
                'correlation_id' => $correlationId,
                'error'          => $e->getMessage(),
            ]);

            return response()->json([
                'status'         => 'error',
                'message'        => 'Service temporarily unavailable — event could not be queued',
                'correlation_id' => $correlationId,
            ], 503);
        }

        return response()->json([
            'status'         => 'accepted',
            'event_id'       => $eventId,
            'correlation_id' => $correlationId,
            'message'        => 'Event accepted for processing',
        ], 202);
    }
}
