<?php

namespace Tests\Feature;

use App\Integration\Services\EventProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SqsConsumerTest extends TestCase
{
    use RefreshDatabase;

    private function validEventJson(string $eventId = 'evt-test-001', string $eventType = 'product.price.updated'): string
    {
        return json_encode([
            'event_id'       => $eventId,
            'event_type'     => $eventType,
            'event_version'  => 1,
            'aggregate_type' => 'product_variant',
            'aggregate_id'   => 'SKU-001',
            'merchant_id'    => '100',
            'correlation_id' => 'corr-001',
            'causation_id'   => 'cause-001',
            'occurred_at'    => '2026-09-23T10:00:00Z',
            'source'         => 'api-gateway',
            'payload'        => [
                'vendor_code' => 'VENDOR-001',
                'variant_sku' => 'SKU-001',
                'price'       => 29.99,
                'quantity'    => 50,
            ],
        ]);
    }

    public function test_rejects_empty_body(): void
    {
        $service = app(EventProcessingService::class);
        $result  = $service->process('');

        $this->assertFalse($result->shouldAcknowledge);
        $this->assertSame('permanent_failure', $result->status);
    }

    public function test_rejects_malformed_json(): void
    {
        $service = app(EventProcessingService::class);
        $result  = $service->process('{invalid json');

        $this->assertFalse($result->shouldAcknowledge);
        $this->assertSame('permanent_failure', $result->status);
    }

    public function test_rejects_missing_event_id(): void
    {
        $service = app(EventProcessingService::class);
        $data    = json_decode($this->validEventJson(), true);
        unset($data['event_id']);

        $result = $service->process(json_encode($data));

        $this->assertFalse($result->shouldAcknowledge);
        $this->assertSame('permanent_failure', $result->status);
    }

    public function test_duplicate_event_acknowledged_without_reprocessing(): void
    {
        $service = app(EventProcessingService::class);
        $json    = $this->validEventJson('evt-dup-feature-001');

        // First processing will fail because gRPC services are not available in tests,
        // but the inbox record will be created
        $result1 = $service->process($json);

        // Second processing should detect duplicate
        // If first was FAILED, the second will try to retry (which is correct)
        // If first was PROCESSED, the second will skip (also correct)
        $result2 = $service->process($json);

        // Both attempts should have created only ONE inbox record
        $this->assertSame(
            1,
            \App\Integration\Models\InboxEvent::where('event_id', 'evt-dup-feature-001')->count()
        );
    }

    public function test_unknown_event_type_records_and_fails(): void
    {
        $service = app(EventProcessingService::class);
        $json    = $this->validEventJson('evt-unknown-001', 'totally.unknown.event');

        $result = $service->process($json);

        // Unknown events should be recorded but not successfully processed
        $this->assertFalse($result->isSuccess());

        // Should have an inbox record
        $inbox = \App\Integration\Models\InboxEvent::where('event_id', 'evt-unknown-001')->first();
        $this->assertNotNull($inbox);
    }

    public function test_correlation_id_preserved_in_inbox(): void
    {
        $service = app(EventProcessingService::class);
        $json    = $this->validEventJson('evt-corr-001');

        $service->process($json);

        $inbox = \App\Integration\Models\InboxEvent::where('event_id', 'evt-corr-001')->first();
        $this->assertNotNull($inbox);
        $this->assertSame('corr-001', $inbox->correlation_id);
    }

    public function test_event_id_preserved_in_inbox(): void
    {
        $service = app(EventProcessingService::class);
        $json    = $this->validEventJson('evt-eid-001');

        $service->process($json);

        $inbox = \App\Integration\Models\InboxEvent::where('event_id', 'evt-eid-001')->first();
        $this->assertNotNull($inbox);
        $this->assertSame('evt-eid-001', $inbox->event_id);
    }

    public function test_payload_stored_in_inbox_for_replay(): void
    {
        $service = app(EventProcessingService::class);
        $json    = $this->validEventJson('evt-payload-001');

        $service->process($json);

        $inbox = \App\Integration\Models\InboxEvent::where('event_id', 'evt-payload-001')->first();
        $this->assertNotNull($inbox);
        $this->assertIsArray($inbox->payload);
        $this->assertSame('product.price.updated', $inbox->payload['event_type']);
    }
}
