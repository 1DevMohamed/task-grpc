<?php

namespace App\Integration\DTOs;

use DateTimeImmutable;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Typed value object representing a standardized event envelope.
 *
 * Every event flowing through the integration architecture MUST be
 * wrapped in this envelope. This guarantees a consistent structure
 * for tracing, idempotency, and routing.
 */
final readonly class EventEnvelope implements Arrayable, JsonSerializable
{
    public function __construct(
        public string  $eventId,
        public string  $eventType,
        public int     $eventVersion,
        public string  $aggregateType,
        public string  $aggregateId,
        public string  $merchantId,
        public string  $correlationId,
        public string  $causationId,
        public string  $occurredAt,
        public string  $source,
        public array   $payload,
    ) {}

    /**
     * Create an EventEnvelope from a decoded JSON array.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException if required fields are missing
     */
    public static function fromArray(array $data): self
    {
        $missing = [];

        foreach (['event_id', 'event_type', 'aggregate_type', 'aggregate_id', 'correlation_id', 'payload'] as $field) {
            if (! isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
                $missing[] = $field;
            }
        }

        if (! empty($missing)) {
            throw new InvalidArgumentException(
                'Event envelope missing required fields: ' . implode(', ', $missing)
            );
        }

        if (! is_array($data['payload'])) {
            throw new InvalidArgumentException('Event envelope payload must be an array');
        }

        return new self(
            eventId:       (string) $data['event_id'],
            eventType:     (string) $data['event_type'],
            eventVersion:  (int) ($data['event_version'] ?? 1),
            aggregateType: (string) $data['aggregate_type'],
            aggregateId:   (string) $data['aggregate_id'],
            merchantId:    (string) ($data['merchant_id'] ?? ''),
            correlationId: (string) $data['correlation_id'],
            causationId:   (string) ($data['causation_id'] ?? $data['correlation_id']),
            occurredAt:    (string) ($data['occurred_at'] ?? now()->toIso8601String()),
            source:        (string) ($data['source'] ?? 'unknown'),
            payload:       (array) $data['payload'],
        );
    }

    /**
     * Create an EventEnvelope from a raw JSON string (SQS message body).
     *
     * @throws InvalidArgumentException if JSON is malformed or fields are missing
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                'Malformed JSON in event envelope: ' . json_last_error_msg()
            );
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('Event envelope must decode to an array');
        }

        return self::fromArray($data);
    }

    /**
     * Get a context array suitable for structured logging.
     *
     * @return array<string, mixed>
     */
    public function loggingContext(): array
    {
        return [
            'event_id'       => $this->eventId,
            'event_type'     => $this->eventType,
            'event_version'  => $this->eventVersion,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id'   => $this->aggregateId,
            'merchant_id'    => $this->merchantId,
            'correlation_id' => $this->correlationId,
            'causation_id'   => $this->causationId,
            'source'         => $this->source,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id'       => $this->eventId,
            'event_type'     => $this->eventType,
            'event_version'  => $this->eventVersion,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id'   => $this->aggregateId,
            'merchant_id'    => $this->merchantId,
            'correlation_id' => $this->correlationId,
            'causation_id'   => $this->causationId,
            'occurred_at'    => $this->occurredAt,
            'source'         => $this->source,
            'payload'        => $this->payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
