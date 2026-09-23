<?php

namespace App\Integration\Logging;

use App\Integration\DTOs\EventEnvelope;
use Illuminate\Support\Facades\Log;

/**
 * Structured logging wrapper for integration events.
 *
 * Every log entry includes the full event context (event_id, correlation_id, etc.)
 * to enable end-to-end tracing across services.
 */
class IntegrationLogger
{
    /**
     * Log event received from SQS.
     */
    public function eventReceived(EventEnvelope $envelope): void
    {
        Log::info('integration.event.received', [
            ...$envelope->loggingContext(),
            'status' => 'received',
        ]);
    }

    /**
     * Log duplicate event detected.
     */
    public function duplicateEvent(EventEnvelope $envelope, string $existingStatus): void
    {
        Log::info('integration.event.duplicate', [
            ...$envelope->loggingContext(),
            'status'          => 'duplicate',
            'existing_status' => $existingStatus,
        ]);
    }

    /**
     * Log event dispatched to handler.
     */
    public function dispatchingToHandler(EventEnvelope $envelope, string $handlerClass): void
    {
        Log::info('integration.event.dispatching', [
            ...$envelope->loggingContext(),
            'handler' => class_basename($handlerClass),
            'status'  => 'dispatching',
        ]);
    }

    /**
     * Log event processed successfully.
     */
    public function eventProcessed(EventEnvelope $envelope, float $durationMs, int $attempt): void
    {
        Log::info('integration.event.processed', [
            ...$envelope->loggingContext(),
            'status'      => 'processed',
            'duration_ms' => round($durationMs, 2),
            'attempt'     => $attempt,
        ]);
    }

    /**
     * Log event processing failure.
     */
    public function eventFailed(
        EventEnvelope $envelope,
        string        $error,
        string        $errorCategory,
        float         $durationMs,
        int           $attempt,
        bool          $willRetry,
    ): void {
        Log::error('integration.event.failed', [
            ...$envelope->loggingContext(),
            'status'         => 'failed',
            'error'          => mb_substr($error, 0, 2000),
            'error_category' => $errorCategory,
            'duration_ms'    => round($durationMs, 2),
            'attempt'        => $attempt,
            'will_retry'     => $willRetry,
        ]);
    }

    /**
     * Log unknown event type received.
     */
    public function unknownEventType(EventEnvelope $envelope): void
    {
        Log::warning('integration.event.unknown_type', [
            ...$envelope->loggingContext(),
            'status'  => 'unknown_event_type',
            'message' => "No handler registered for event type: '{$envelope->eventType}'",
        ]);
    }

    /**
     * Log RPC call.
     */
    public function rpcCall(
        string $service,
        string $method,
        string $correlationId,
        string $eventId,
        string $status,
        float  $durationMs,
        ?string $error = null,
    ): void {
        $context = [
            'service'        => $service,
            'method'         => $method,
            'correlation_id' => $correlationId,
            'event_id'       => $eventId,
            'status'         => $status,
            'duration_ms'    => round($durationMs, 2),
        ];

        if ($error !== null) {
            $context['error'] = mb_substr($error, 0, 2000);
        }

        $level = $status === 'success' ? 'info' : 'error';
        Log::log($level, "integration.rpc.{$status}", $context);
    }

    /**
     * Log validation/parsing error for a raw message.
     */
    public function messageValidationFailed(string $error, ?string $rawBody = null): void
    {
        Log::error('integration.message.validation_failed', [
            'status'   => 'validation_failed',
            'error'    => mb_substr($error, 0, 2000),
            'raw_body' => $rawBody !== null ? mb_substr($rawBody, 0, 1000) : null,
        ]);
    }

    /**
     * Log stale processing event reclaimed.
     */
    public function staleEventReclaimed(EventEnvelope $envelope): void
    {
        Log::warning('integration.event.stale_reclaimed', [
            ...$envelope->loggingContext(),
            'status' => 'stale_reclaimed',
        ]);
    }
}
