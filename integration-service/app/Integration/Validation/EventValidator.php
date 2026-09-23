<?php

namespace App\Integration\Validation;

use App\Integration\DTOs\EventEnvelope;
use InvalidArgumentException;

/**
 * Validates inbound SQS messages before they can be processed.
 *
 * This validator runs BEFORE the event enters the inbox or dispatcher.
 * Invalid events are rejected early with clear error messages.
 */
class EventValidator
{
    /**
     * Known event types that the system can handle.
     * Unknown types are still recorded but flagged.
     */
    private const array KNOWN_EVENT_TYPES = [
        'product.price.updated',
        'product.stock.updated',
    ];

    /**
     * Validate and parse a raw JSON message body into an EventEnvelope.
     *
     * @param  string  $rawBody  The raw SQS message body (JSON string)
     *
     * @throws InvalidArgumentException if validation fails
     */
    public function validate(string $rawBody): EventEnvelope
    {
        if (trim($rawBody) === '') {
            throw new InvalidArgumentException('Empty message body received');
        }

        // fromJson handles JSON parsing errors and required field validation
        $envelope = EventEnvelope::fromJson($rawBody);

        // Additional business validations
        $this->validateEventId($envelope);
        $this->validateEventType($envelope);
        $this->validateEventVersion($envelope);

        return $envelope;
    }

    /**
     * Check whether the event type is known to the system.
     * Note: unknown types are NOT rejected here — they are recorded and flagged.
     */
    public function isKnownEventType(string $eventType): bool
    {
        return in_array($eventType, self::KNOWN_EVENT_TYPES, true);
    }

    private function validateEventId(EventEnvelope $envelope): void
    {
        if (strlen($envelope->eventId) < 8) {
            throw new InvalidArgumentException(
                "Event ID '{$envelope->eventId}' is too short — expected a UUID or equivalent unique identifier"
            );
        }
    }

    private function validateEventType(EventEnvelope $envelope): void
    {
        // Event type must follow dotted notation: segment.segment[.segment...]
        if (! preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $envelope->eventType)) {
            throw new InvalidArgumentException(
                "Invalid event_type format: '{$envelope->eventType}'. Expected dotted notation like 'product.price.updated'"
            );
        }
    }

    private function validateEventVersion(EventEnvelope $envelope): void
    {
        if ($envelope->eventVersion < 1) {
            throw new InvalidArgumentException(
                "Invalid event_version: {$envelope->eventVersion}. Must be >= 1"
            );
        }
    }
}
