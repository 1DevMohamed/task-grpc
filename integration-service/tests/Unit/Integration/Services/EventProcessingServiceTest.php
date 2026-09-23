<?php

namespace Tests\Unit\Integration\Services;

use App\Integration\Dispatcher\MessageDispatcher;
use App\Integration\DTOs\EventEnvelope;
use App\Integration\Exceptions\ErrorClassifier;
use App\Integration\Exceptions\PermanentFailureException;
use App\Integration\Exceptions\TransientFailureException;
use App\Integration\Logging\IntegrationLogger;
use App\Integration\Metrics\MetricsCollector;
use App\Integration\Models\InboxEvent;
use App\Integration\Repositories\InboxRepository;
use App\Integration\Retry\RetryPolicy;
use App\Integration\Services\EventProcessingService;
use App\Integration\Validation\EventValidator;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

class EventProcessingServiceTest extends TestCase
{
    private EventValidator|MockInterface $validator;
    private InboxRepository|MockInterface $inboxRepo;
    private MessageDispatcher|MockInterface $dispatcher;
    private ErrorClassifier $errorClassifier;
    private RetryPolicy|MockInterface $retryPolicy;
    private IntegrationLogger|MockInterface $logger;
    private MetricsCollector|MockInterface $metrics;
    private EventProcessingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator       = Mockery::mock(EventValidator::class);
        $this->inboxRepo       = Mockery::mock(InboxRepository::class);
        $this->dispatcher      = Mockery::mock(MessageDispatcher::class);
        $this->errorClassifier = new ErrorClassifier();
        $this->retryPolicy     = Mockery::mock(RetryPolicy::class);
        $this->logger          = Mockery::mock(IntegrationLogger::class)->shouldIgnoreMissing();
        $this->metrics         = Mockery::mock(MetricsCollector::class)->shouldIgnoreMissing();

        $this->service = new EventProcessingService(
            $this->validator,
            $this->inboxRepo,
            $this->dispatcher,
            $this->errorClassifier,
            $this->retryPolicy,
            $this->logger,
            $this->metrics,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeEnvelope(): EventEnvelope
    {
        return EventEnvelope::fromArray([
            'event_id'       => 'evt-001',
            'event_type'     => 'product.price.updated',
            'aggregate_type' => 'product_variant',
            'aggregate_id'   => 'SKU-001',
            'correlation_id' => 'corr-001',
            'payload'        => ['vendor_code' => 'V1', 'variant_sku' => 'S1', 'price' => 10],
        ]);
    }

    private function makeInbox(string $status = InboxEvent::STATUS_RECEIVED, int $attempts = 0): InboxEvent
    {
        $inbox = new InboxEvent();
        $inbox->id = 1;
        $inbox->event_id = 'evt-001';
        $inbox->event_type = 'product.price.updated';
        $inbox->status = $status;
        $inbox->attempts = $attempts;
        return $inbox;
    }

    // ── Happy path ─────────────────────────────────────────

    public function test_processes_valid_event_successfully(): void
    {
        $envelope = $this->makeEnvelope();
        $inbox    = $this->makeInbox();

        $this->validator->shouldReceive('validate')->once()->andReturn($envelope);
        $this->inboxRepo->shouldReceive('recordEvent')->once()->andReturn(['inbox' => $inbox, 'is_new' => true]);
        $this->inboxRepo->shouldReceive('acquireForProcessing')->once()->andReturn(true);
        $this->dispatcher->shouldReceive('dispatch')->once()->with($envelope);
        $this->inboxRepo->shouldReceive('markProcessed')->once();
        $this->retryPolicy->shouldReceive('shouldRetry')->andReturn(true);

        $result = $this->service->process(json_encode($envelope->toArray()));

        $this->assertTrue($result->shouldAcknowledge);
        $this->assertSame('success', $result->status);
    }

    // ── Validation failures ────────────────────────────────

    public function test_rejects_malformed_json(): void
    {
        $this->validator->shouldReceive('validate')
            ->once()
            ->andThrow(new \InvalidArgumentException('Malformed JSON'));

        $result = $this->service->process('{bad json}');

        $this->assertFalse($result->shouldAcknowledge);
        $this->assertSame('permanent_failure', $result->status);
    }

    public function test_rejects_missing_event_id(): void
    {
        $this->validator->shouldReceive('validate')
            ->once()
            ->andThrow(new \InvalidArgumentException('missing required fields: event_id'));

        $result = $this->service->process('{}');

        $this->assertFalse($result->shouldAcknowledge);
        $this->assertSame('permanent_failure', $result->status);
    }

    // ── Duplicate detection ────────────────────────────────

    public function test_skips_already_processed_event(): void
    {
        $envelope = $this->makeEnvelope();
        $inbox    = $this->makeInbox(InboxEvent::STATUS_PROCESSED);

        $this->validator->shouldReceive('validate')->once()->andReturn($envelope);
        $this->inboxRepo->shouldReceive('recordEvent')
            ->once()
            ->andReturn(['inbox' => $inbox, 'is_new' => false]);

        $result = $this->service->process(json_encode($envelope->toArray()));

        $this->assertTrue($result->shouldAcknowledge);
        $this->assertSame('already_processed', $result->status);
    }

    // ── Permanent failure ──────────────────────────────────

    public function test_handles_permanent_failure_from_handler(): void
    {
        $envelope = $this->makeEnvelope();
        $inbox    = $this->makeInbox();

        $this->validator->shouldReceive('validate')->once()->andReturn($envelope);
        $this->inboxRepo->shouldReceive('recordEvent')->once()->andReturn(['inbox' => $inbox, 'is_new' => true]);
        $this->inboxRepo->shouldReceive('acquireForProcessing')->once()->andReturn(true);
        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new PermanentFailureException('Vendor not found'));
        $this->inboxRepo->shouldReceive('markFailed')->once();
        $this->retryPolicy->shouldReceive('shouldRetry')->andReturn(true);

        $result = $this->service->process(json_encode($envelope->toArray()));

        // Permanent failures should NOT acknowledge (let SQS handle DLQ via maxReceiveCount)
        $this->assertFalse($result->shouldAcknowledge);
    }

    // ── Transient failure ──────────────────────────────────

    public function test_handles_transient_failure_for_retry(): void
    {
        $envelope = $this->makeEnvelope();
        $inbox    = $this->makeInbox();

        $this->validator->shouldReceive('validate')->once()->andReturn($envelope);
        $this->inboxRepo->shouldReceive('recordEvent')->once()->andReturn(['inbox' => $inbox, 'is_new' => true]);
        $this->inboxRepo->shouldReceive('acquireForProcessing')->once()->andReturn(true);
        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new TransientFailureException('Service unavailable'));
        $this->inboxRepo->shouldReceive('markFailed')->once();
        $this->retryPolicy->shouldReceive('shouldRetry')->andReturn(true);

        $result = $this->service->process(json_encode($envelope->toArray()));

        $this->assertFalse($result->shouldAcknowledge);
        $this->assertSame('transient_failure', $result->status);
    }

    // ── Lock contention ────────────────────────────────────

    public function test_skips_when_lock_not_acquired(): void
    {
        $envelope = $this->makeEnvelope();
        $inbox    = $this->makeInbox();

        $this->validator->shouldReceive('validate')->once()->andReturn($envelope);
        $this->inboxRepo->shouldReceive('recordEvent')->once()->andReturn(['inbox' => $inbox, 'is_new' => true]);
        $this->inboxRepo->shouldReceive('acquireForProcessing')->once()->andReturn(false);

        $result = $this->service->process(json_encode($envelope->toArray()));

        $this->assertFalse($result->shouldAcknowledge);
        $this->assertSame('transient_failure', $result->status);
    }

    // ── Database unavailable ───────────────────────────────

    public function test_retries_when_inbox_db_unavailable(): void
    {
        $envelope = $this->makeEnvelope();

        $this->validator->shouldReceive('validate')->once()->andReturn($envelope);
        $this->inboxRepo->shouldReceive('recordEvent')
            ->once()
            ->andThrow(new \RuntimeException('SQLSTATE connection failed'));

        $result = $this->service->process(json_encode($envelope->toArray()));

        $this->assertFalse($result->shouldAcknowledge);
        $this->assertSame('transient_failure', $result->status);
    }
}
