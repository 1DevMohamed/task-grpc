<?php

namespace App\Integration\Dispatcher;

use App\Integration\Contracts\EventHandlerInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Registry mapping event types to handler classes.
 *
 * Adding a new event handler requires only registering it here.
 * The dispatcher does not need to be modified.
 */
class EventHandlerRegistry
{
    /** @var array<string, class-string<EventHandlerInterface>> */
    private array $handlers = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Register a handler class for one or more event types.
     *
     * @param  class-string<EventHandlerInterface>  $handlerClass
     * @param  string[]  $eventTypes
     */
    public function register(string $handlerClass, array $eventTypes): void
    {
        foreach ($eventTypes as $eventType) {
            $this->handlers[$eventType] = $handlerClass;
        }
    }

    /**
     * Resolve the handler instance for the given event type.
     *
     * Returns null if no handler is registered for the event type.
     */
    public function resolve(string $eventType): ?EventHandlerInterface
    {
        $handlerClass = $this->handlers[$eventType] ?? null;

        if ($handlerClass === null) {
            return null;
        }

        return $this->container->make($handlerClass);
    }

    /**
     * Check if a handler is registered for the given event type.
     */
    public function has(string $eventType): bool
    {
        return isset($this->handlers[$eventType]);
    }

    /**
     * Get all registered event types.
     *
     * @return string[]
     */
    public function registeredEventTypes(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * Get the handler class name for the given event type.
     *
     * @return class-string<EventHandlerInterface>|null
     */
    public function getHandlerClass(string $eventType): ?string
    {
        return $this->handlers[$eventType] ?? null;
    }
}
