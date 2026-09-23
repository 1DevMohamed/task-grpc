<?php

namespace App\Integration\Services;

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
use App\Integration\Validation\EventValidator;
use InvalidArgumentException;
use Throwable;

/**
 * Orchestrates the complete event processing pipeline.
 *
 * Flow:
 * 1. Validate raw message → EventEnvelope
 * 2. Record in inbox (idempotency check)
 * 3. Acquire processing lock (concurrency-safe)
 * 4. Dispatch to handler
 * 5. Update inbox status
 * 6. Classify errors and determine retry behavior
 *
 * Returns a ProcessingResult indicating whether the SQS message
 * should be deleted (acknowledged) or left for retry.
 */
class EventProcessingService
{
    public function __construct(
        private readonly EventValidator     $validator,
        private readonly InboxRepository    $inboxRepo,
        private readonly MessageDispatcher  $dispatcher,
        private readonly ErrorClassifier    $errorClassifier,
        private readonly RetryPolicy        $retryPolicy,
        private readonly IntegrationLogger  $logger,
        private readonly MetricsCollector   $metrics,
    ) {}

    /**
     * Process a raw SQS message body.
     *
     * @param  string  $rawBody  The raw JSON message body from SQS
     * @return ProcessingResult
     */
    public function process(string $rawBody): ProcessingResult
    {
        $startTime = microtime(true);

        // ── Step 1: Validate and parse ─────────────────────

        try {
            $envelope = $this->validator->validate($rawBody);
        } catch (InvalidArgumentException $e) {
            $this->logger->messageValidationFailed($e->getMessage(), $rawBody);
            $this->metrics->messageFailed('unknown', 'validation_error');

            // Malformed messages should not be retried — they'll never succeed
            return ProcessingResult::permanentFailure(
                "Validation failed: {$e->getMessage()}"
            );
        }

        $this->logger->eventReceived($envelope);
        $this->metrics->messageReceived($envelope->eventType);

        // ── Step 2: Inbox idempotency check ────────────────

        try {
            ['inbox' => $inbox, 'is_new' => $isNew] = $this->inboxRepo->recordEvent($envelope);
        } catch (Throwable $e) {
            // Database unavailable — don't acknowledge, let SQS retry
            $this->logger->eventFailed(
                $envelope, "Inbox DB error: {$e->getMessage()}", 'transient', 0, 0, true
            );
            return ProcessingResult::transientFailure("Inbox DB error: {$e->getMessage()}");
        }

        if (! $isNew) {
            return $this->handleExistingEvent($envelope, $inbox, $startTime);
        }

        // ── Step 3: Acquire processing lock ────────────────

        return $this->executeProcessing($envelope, $inbox, $startTime);
    }

    /**
     * Handle an event that already exists in the inbox.
     */
    private function handleExistingEvent(
        EventEnvelope $envelope,
        InboxEvent    $inbox,
        float         $startTime,
    ): ProcessingResult {
        // Already successfully processed — acknowledge SQS message (dedup)
        if ($inbox->isProcessed()) {
            $this->logger->duplicateEvent($envelope, InboxEvent::STATUS_PROCESSED);
            $this->metrics->duplicateEvent($envelope->eventType);

            return ProcessingResult::alreadyProcessed();
        }

        // Currently being processed by another worker
        if ($inbox->isProcessing()) {
            $staleThreshold = config('integration.inbox.stale_threshold_seconds', 120);

            if ($inbox->isStaleProcessing($staleThreshold)) {
                // Reclaim stale event — the original worker likely crashed
                if ($this->inboxRepo->reclaimStaleEvent($inbox, $staleThreshold)) {
                    $this->logger->staleEventReclaimed($envelope);
                    return $this->executeProcessing($envelope, $inbox, $startTime);
                }
            }

            // Another worker is actively processing — skip, let SQS retry later
            $this->logger->duplicateEvent($envelope, InboxEvent::STATUS_PROCESSING);
            return ProcessingResult::transientFailure('Event is being processed by another worker');
        }

        // Failed event — retry if policy allows
        if ($inbox->isFailed()) {
            if ($this->retryPolicy->shouldRetry($inbox->attempts)) {
                return $this->executeProcessing($envelope, $inbox, $startTime);
            }

            // Max retries exceeded — let SQS move it to DLQ
            $this->metrics->messageDlq($envelope->eventType);
            return ProcessingResult::permanentFailure(
                "Max application retries ({$inbox->attempts}) exceeded"
            );
        }

        // RECEIVED but not yet processed — proceed
        return $this->executeProcessing($envelope, $inbox, $startTime);
    }

    /**
     * Execute the actual event processing (acquire lock → dispatch → update status).
     */
    private function executeProcessing(
        EventEnvelope $envelope,
        InboxEvent    $inbox,
        float         $startTime,
    ): ProcessingResult {
        // Atomically acquire processing lock
        if (! $this->inboxRepo->acquireForProcessing($inbox)) {
            // Another worker grabbed it — skip
            $this->logger->duplicateEvent($envelope, 'lock_contention');
            return ProcessingResult::transientFailure('Failed to acquire processing lock');
        }

        // Apply retry delay if this is a retry attempt
        if ($inbox->attempts > 1) {
            $this->retryPolicy->wait($inbox->attempts);
            $this->metrics->messageRetried($envelope->eventType);
        }

        // ── Step 4: Dispatch to handler ────────────────────

        try {
            $this->dispatcher->dispatch($envelope);

            $durationMs = (microtime(true) - $startTime) * 1000;

            // ── Step 5: Mark as processed ──────────────────
            $this->inboxRepo->markProcessed($inbox);
            $this->logger->eventProcessed($envelope, $durationMs, $inbox->attempts);
            $this->metrics->messageProcessed($envelope->eventType, $durationMs);

            return ProcessingResult::success();

        } catch (PermanentFailureException $e) {
            return $this->handleFailure($envelope, $inbox, $e, $startTime, false);

        } catch (TransientFailureException $e) {
            return $this->handleFailure($envelope, $inbox, $e, $startTime, true);

        } catch (Throwable $e) {
            $shouldRetry = $this->errorClassifier->shouldRetry($e);
            return $this->handleFailure($envelope, $inbox, $e, $startTime, $shouldRetry);
        }
    }

    /**
     * Handle a processing failure.
     */
    private function handleFailure(
        EventEnvelope $envelope,
        InboxEvent    $inbox,
        Throwable     $error,
        float         $startTime,
        bool          $shouldRetry,
    ): ProcessingResult {
        $durationMs    = (microtime(true) - $startTime) * 1000;
        $errorCategory = $this->errorClassifier->classify($error);
        $willRetry     = $shouldRetry && $this->retryPolicy->shouldRetry($inbox->attempts);

        $this->inboxRepo->markFailed($inbox, $error->getMessage(), $errorCategory);

        $this->logger->eventFailed(
            $envelope,
            $error->getMessage(),
            $errorCategory,
            $durationMs,
            $inbox->attempts,
            $willRetry,
        );

        $this->metrics->messageFailed($envelope->eventType, $errorCategory);

        if ($willRetry) {
            // Don't acknowledge — let SQS retry after visibility timeout
            return ProcessingResult::transientFailure($error->getMessage());
        }

        // Permanent failure or max retries — acknowledge to prevent infinite loop
        // SQS maxReceiveCount will handle DLQ routing
        $this->metrics->messageDlq($envelope->eventType);
        return ProcessingResult::permanentFailure($error->getMessage());
    }
}
