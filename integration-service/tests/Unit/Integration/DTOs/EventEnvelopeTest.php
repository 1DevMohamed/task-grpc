<?php

namespace Tests\Unit\Integration\DTOs;

use App\Integration\DTOs\EventEnvelope;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class EventEnvelopeTest extends TestCase
{
    private function validData(): array
    {
        return [
            'event_id'       => 'evt-12345678-abcd-1234-efgh-000000000001',
            'event_type'     => 'product.price.updated',
            'event_version'  => 1,
            'aggregate_type' => 'product_variant',
            'aggregate_id'   => 'SKU-001',
            'merchant_id'    => '100',
            'correlation_id' => 'corr-0001',
            'causation_id'   => 'cause-0001',
            'occurred_at'    => '2026-09-23T10:00:00Z',
            'source'         => 'api-gateway',
            'payload'        => ['vendor_code' => 'VENDOR-001', 'variant_sku' => 'SKU-001', 'price' => 29.99],
        ];
    }

    public function test_creates_from_valid_array(): void
    {
        $envelope = EventEnvelope::fromArray($this->validData());

        $this->assertSame('evt-12345678-abcd-1234-efgh-000000000001', $envelope->eventId);
        $this->assertSame('product.price.updated', $envelope->eventType);
        $this->assertSame(1, $envelope->eventVersion);
        $this->assertSame('product_variant', $envelope->aggregateType);
        $this->assertSame('SKU-001', $envelope->aggregateId);
        $this->assertSame('100', $envelope->merchantId);
        $this->assertSame('corr-0001', $envelope->correlationId);
        $this->assertSame('cause-0001', $envelope->causationId);
        $this->assertSame('api-gateway', $envelope->source);
        $this->assertSame(29.99, $envelope->payload['price']);
    }

    public function test_creates_from_valid_json(): void
    {
        $json     = json_encode($this->validData());
        $envelope = EventEnvelope::fromJson($json);

        $this->assertSame('product.price.updated', $envelope->eventType);
    }

    public function test_rejects_missing_event_id(): void
    {
        $data = $this->validData();
        unset($data['event_id']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('event_id');

        EventEnvelope::fromArray($data);
    }

    public function test_rejects_missing_event_type(): void
    {
        $data = $this->validData();
        unset($data['event_type']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('event_type');

        EventEnvelope::fromArray($data);
    }

    public function test_rejects_missing_correlation_id(): void
    {
        $data = $this->validData();
        unset($data['correlation_id']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('correlation_id');

        EventEnvelope::fromArray($data);
    }

    public function test_rejects_missing_payload(): void
    {
        $data = $this->validData();
        unset($data['payload']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('payload');

        EventEnvelope::fromArray($data);
    }

    public function test_rejects_non_array_payload(): void
    {
        $data = $this->validData();
        $data['payload'] = 'not-an-array';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('payload must be an array');

        EventEnvelope::fromArray($data);
    }

    public function test_rejects_malformed_json(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed JSON');

        EventEnvelope::fromJson('{bad json');
    }

    public function test_defaults_event_version_to_1(): void
    {
        $data = $this->validData();
        unset($data['event_version']);

        $envelope = EventEnvelope::fromArray($data);

        $this->assertSame(1, $envelope->eventVersion);
    }

    public function test_defaults_causation_id_to_correlation_id(): void
    {
        $data = $this->validData();
        unset($data['causation_id']);

        $envelope = EventEnvelope::fromArray($data);

        $this->assertSame($data['correlation_id'], $envelope->causationId);
    }

    public function test_to_array_roundtrips(): void
    {
        $data     = $this->validData();
        $envelope = EventEnvelope::fromArray($data);
        $result   = $envelope->toArray();

        $this->assertSame($data['event_id'], $result['event_id']);
        $this->assertSame($data['event_type'], $result['event_type']);
        $this->assertSame($data['payload'], $result['payload']);
    }

    public function test_logging_context_excludes_payload(): void
    {
        $envelope = EventEnvelope::fromArray($this->validData());
        $context  = $envelope->loggingContext();

        $this->assertArrayHasKey('event_id', $context);
        $this->assertArrayHasKey('correlation_id', $context);
        $this->assertArrayNotHasKey('payload', $context);
    }

    public function test_rejects_empty_string_fields(): void
    {
        $data = $this->validData();
        $data['event_id'] = '  ';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('event_id');

        EventEnvelope::fromArray($data);
    }
}
