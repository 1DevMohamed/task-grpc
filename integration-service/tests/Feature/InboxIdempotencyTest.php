<?php

namespace Tests\Feature;

use App\Integration\DTOs\EventEnvelope;
use App\Integration\Models\InboxEvent;
use App\Integration\Repositories\InboxRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function makeEnvelope(string $eventId = 'evt-001'): EventEnvelope
    {
        return EventEnvelope::fromArray([
            'event_id'       => $eventId,
            'event_type'     => 'product.price.updated',
            'aggregate_type' => 'product_variant',
            'aggregate_id'   => 'SKU-001',
            'correlation_id' => 'corr-001',
            'payload'        => ['vendor_code' => 'V1', 'variant_sku' => 'S1', 'price' => 10],
        ]);
    }

    public function test_records_new_event(): void
    {
        $repo = new InboxRepository();
        $envelope = $this->makeEnvelope('evt-new-001');

        ['inbox' => $inbox, 'is_new' => $isNew] = $repo->recordEvent($envelope);

        $this->assertTrue($isNew);
        $this->assertSame('evt-new-001', $inbox->event_id);
        $this->assertSame(InboxEvent::STATUS_RECEIVED, $inbox->status);
        $this->assertSame(0, $inbox->attempts);
    }

    public function test_detects_duplicate_event(): void
    {
        $repo = new InboxRepository();
        $envelope = $this->makeEnvelope('evt-dup-001');

        // First insert
        $first = $repo->recordEvent($envelope);
        $this->assertTrue($first['is_new']);

        // Second insert — same event_id
        $second = $repo->recordEvent($envelope);
        $this->assertFalse($second['is_new']);
        $this->assertSame($first['inbox']->id, $second['inbox']->id);
    }

    public function test_acquires_processing_lock(): void
    {
        $repo = new InboxRepository();
        $envelope = $this->makeEnvelope('evt-lock-001');

        ['inbox' => $inbox] = $repo->recordEvent($envelope);

        // First acquire should succeed
        $acquired = $repo->acquireForProcessing($inbox);
        $this->assertTrue($acquired);

        $inbox->refresh();
        $this->assertSame(InboxEvent::STATUS_PROCESSING, $inbox->status);
        $this->assertSame(1, $inbox->attempts);
    }

    public function test_lock_prevents_concurrent_processing(): void
    {
        $repo = new InboxRepository();
        $envelope = $this->makeEnvelope('evt-concurrent-001');

        ['inbox' => $inbox] = $repo->recordEvent($envelope);

        // First worker acquires
        $this->assertTrue($repo->acquireForProcessing($inbox));

        // Second worker tries — should fail (status is now PROCESSING)
        $inbox2 = InboxEvent::where('event_id', 'evt-concurrent-001')->first();
        $this->assertFalse($repo->acquireForProcessing($inbox2));
    }

    public function test_marks_processed(): void
    {
        $repo = new InboxRepository();
        $envelope = $this->makeEnvelope('evt-done-001');

        ['inbox' => $inbox] = $repo->recordEvent($envelope);
        $repo->acquireForProcessing($inbox);
        $repo->markProcessed($inbox);

        $inbox->refresh();
        $this->assertSame(InboxEvent::STATUS_PROCESSED, $inbox->status);
        $this->assertNotNull($inbox->processed_at);
    }

    public function test_marks_failed_with_error(): void
    {
        $repo = new InboxRepository();
        $envelope = $this->makeEnvelope('evt-fail-001');

        ['inbox' => $inbox] = $repo->recordEvent($envelope);
        $repo->acquireForProcessing($inbox);
        $repo->markFailed($inbox, 'Vendor not found', 'permanent');

        $inbox->refresh();
        $this->assertSame(InboxEvent::STATUS_FAILED, $inbox->status);
        $this->assertSame('Vendor not found', $inbox->error_message);
        $this->assertSame('permanent', $inbox->error_category);
        $this->assertNotNull($inbox->failed_at);
    }

    public function test_failed_event_can_be_retried(): void
    {
        $repo = new InboxRepository();
        $envelope = $this->makeEnvelope('evt-retry-001');

        ['inbox' => $inbox] = $repo->recordEvent($envelope);
        $repo->acquireForProcessing($inbox);
        $repo->markFailed($inbox, 'Temporary error', 'transient');

        // Should be acquirable again (FAILED → PROCESSING)
        $this->assertTrue($repo->acquireForProcessing($inbox));

        $inbox->refresh();
        $this->assertSame(InboxEvent::STATUS_PROCESSING, $inbox->status);
        $this->assertSame(2, $inbox->attempts);
    }

    public function test_reset_for_replay(): void
    {
        $repo = new InboxRepository();
        $envelope = $this->makeEnvelope('evt-replay-001');

        ['inbox' => $inbox] = $repo->recordEvent($envelope);
        $repo->acquireForProcessing($inbox);
        $repo->markFailed($inbox, 'Some error', 'permanent');

        // Reset for replay
        $reset = $repo->resetForReplay('evt-replay-001');

        $this->assertNotNull($reset);
        $this->assertSame(InboxEvent::STATUS_RECEIVED, $reset->status);
        $this->assertSame(0, $reset->attempts);
        $this->assertNull($reset->error_message);
    }

    public function test_processed_event_skips_on_duplicate_sqs_delivery(): void
    {
        $repo = new InboxRepository();
        $envelope = $this->makeEnvelope('evt-dedup-001');

        // First processing — successful
        ['inbox' => $inbox] = $repo->recordEvent($envelope);
        $repo->acquireForProcessing($inbox);
        $repo->markProcessed($inbox);

        // SQS delivers same message again
        $duplicate = $repo->recordEvent($envelope);
        $this->assertFalse($duplicate['is_new']);
        $this->assertTrue($duplicate['inbox']->isProcessed());
    }

    public function test_unique_constraint_prevents_insert_race(): void
    {
        $repo = new InboxRepository();

        // Simulate two workers trying to insert the same event_id
        $envelope = $this->makeEnvelope('evt-race-001');

        $result1 = $repo->recordEvent($envelope);
        $result2 = $repo->recordEvent($envelope);

        $this->assertTrue($result1['is_new']);
        $this->assertFalse($result2['is_new']);

        // Only one row should exist
        $this->assertSame(1, InboxEvent::where('event_id', 'evt-race-001')->count());
    }

    public function test_stale_processing_detection(): void
    {
        $repo = new InboxRepository();
        $envelope = $this->makeEnvelope('evt-stale-001');

        ['inbox' => $inbox] = $repo->recordEvent($envelope);
        $repo->acquireForProcessing($inbox);

        // Manually backdate processing_started_at to simulate a crashed worker
        $inbox->update(['processing_started_at' => now()->subSeconds(300)]);

        $this->assertTrue($inbox->isStaleProcessing(120));
    }
}
