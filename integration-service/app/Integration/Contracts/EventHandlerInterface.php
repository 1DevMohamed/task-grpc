<?php

namespace App\Integration\Contracts;

use App\Integration\DTOs\EventEnvelope;

/**
 * Contract for all integration event handlers.
 *
 * Handlers receive a typed EventEnvelope and are responsible only for
 * orchestrating RPC calls to domain services. They must NOT contain
 * domain/business logic — that belongs in the domain services.
 */
interface EventHandlerInterface
{
    /**
     * Handle the event.
     *
     * @param  EventEnvelope  $envelope  The validated event envelope
     *
     * @throws \App\Integration\Exceptions\PermanentFailureException  for business/validation errors
     * @throws \App\Integration\Exceptions\TransientFailureException  for transient/retryable errors
     * @throws \Throwable  for unexpected errors
     */
    public function handle(EventEnvelope $envelope): void;

    /**
     * Return the event types this handler supports.
     *
     * @return string[]
     */
    public static function supportedEventTypes(): array;
}
