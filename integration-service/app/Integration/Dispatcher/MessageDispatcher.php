<?php

namespace App\Integration\Dispatcher;

use App\Integration\Contracts\EventHandlerInterface;
use App\Integration\DTOs\EventEnvelope;
use App\Integration\Exceptions\PermanentFailureException;
use App\Integration\Logging\IntegrationLogger;

/**
 * Routes validated events to the appropriate handler.
 *
 * This class is responsible ONLY for routing — no business logic.
 * Unknown event types are flagged and recorded, never silently discarded.
 */
class MessageDispatcher
{
    public function __construct(
        private readonly EventHandlerRegistry $registry,
        private readonly IntegrationLogger    $logger,
    ) {}

    /**
     * Dispatch an event envelope to the appropriate handler.
     *
     * @throws PermanentFailureException if event type is unknown
     * @throws \Throwable propagated from the handler
     */
    public function dispatch(EventEnvelope $envelope): void
    {
        $handler = $this->registry->resolve($envelope->eventType);

        if ($handler === null) {
            $this->logger->unknownEventType($envelope);

            throw new PermanentFailureException(
                message: "No handler registered for event type: '{$envelope->eventType}'",
                errorCategory: 'unknown_event_type',
            );
        }

        $handlerClass = $this->registry->getHandlerClass($envelope->eventType);

        $this->logger->dispatchingToHandler($envelope, $handlerClass ?? get_class($handler));

        $handler->handle($envelope);
    }
}
