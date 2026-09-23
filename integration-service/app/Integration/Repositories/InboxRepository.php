<?php

namespace App\Integration\Repositories;

use App\Integration\DTOs\EventEnvelope;
use App\Integration\Models\InboxEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repository for inbox event management.
 *
 * Handles concurrency-safe event acquisition using:
 * 1. UNIQUE constraint on event_id (prevents duplicate INSERT)
 * 2. Atomic UPDATE ... WHERE (prevents concurrent state transitions)
 *
 * This does NOT rely on `if (!exists()) { create(); }` which is
 * subject to race conditions between the check and the write.
 */
class InboxRepository
{
    /**
     * Attempt to record a new event in the inbox.
     *
     * Returns the InboxEvent if successfully created or already exists.
     * The caller should check the status to determine next action.
     *
     * @return array{inbox: InboxEvent, is_new: bool}
     */
    public function recordEvent(EventEnvelope $envelope): array
    {
        try {
            $inbox = InboxEvent::create([
                'event_id'       => $envelope->eventId,
                'event_type'     => $envelope->eventType,
                'aggregate_type' => $envelope->aggregateType,
                'aggregate_id'   => $envelope->aggregateId,
                'correlation_id' => $envelope->correlationId,
                'merchant_id'    => $envelope->merchantId,
                'status'         => InboxEvent::STATUS_RECEIVED,
                'attempts'       => 0,
                'payload'        => $envelope->toArray(),
                'received_at'    => now(),
            ]);

            return ['inbox' => $inbox, 'is_new' => true];
        } catch (QueryException $e) {
            // UNIQUE constraint violation (SQLite: code 19, MySQL: code 23000)
            if ($this->isDuplicateKeyError($e)) {
                $existing = InboxEvent::where('event_id', $envelope->eventId)->first();

                if ($existing === null) {
                    // Extremely rare: constraint fired but row vanished
                    throw $e;
                }

                return ['inbox' => $existing, 'is_new' => false];
            }

            throw $e;
        }
    }

    /**
     * Atomically acquire processing lock for an event.
     *
     * Uses UPDATE ... WHERE to ensure only one worker can transition
     * the event from RECEIVED/FAILED to PROCESSING at a time.
     *
     * @return bool true if this worker acquired the lock
     */
    public function acquireForProcessing(InboxEvent $inbox): bool
    {
        $affected = DB::table('integration_inbox')
            ->where('id', $inbox->id)
            ->whereIn('status', [InboxEvent::STATUS_RECEIVED, InboxEvent::STATUS_FAILED])
            ->update([
                'status'                => InboxEvent::STATUS_PROCESSING,
                'processing_started_at' => now(),
                'attempts'              => DB::raw('attempts + 1'),
                'updated_at'            => now(),
            ]);

        if ($affected > 0) {
            $inbox->refresh();
            return true;
        }

        return false;
    }

    /**
     * Attempt to reclaim a stale PROCESSING event.
     *
     * A stale event is one where the processing_started_at is older
     * than the configured threshold, indicating the original worker
     * likely crashed.
     */
    public function reclaimStaleEvent(InboxEvent $inbox, int $thresholdSeconds = 120): bool
    {
        $affected = DB::table('integration_inbox')
            ->where('id', $inbox->id)
            ->where('status', InboxEvent::STATUS_PROCESSING)
            ->where('processing_started_at', '<', now()->subSeconds($thresholdSeconds))
            ->update([
                'status'                => InboxEvent::STATUS_PROCESSING,
                'processing_started_at' => now(),
                'attempts'              => DB::raw('attempts + 1'),
                'updated_at'            => now(),
            ]);

        if ($affected > 0) {
            $inbox->refresh();

            Log::warning('Reclaimed stale processing event', [
                'event_id'    => $inbox->event_id,
                'event_type'  => $inbox->event_type,
                'attempts'    => $inbox->attempts,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Mark an event as successfully processed.
     */
    public function markProcessed(InboxEvent $inbox): void
    {
        $inbox->update([
            'status'       => InboxEvent::STATUS_PROCESSED,
            'processed_at' => now(),
            'error_message'  => null,
            'error_category' => null,
        ]);
    }

    /**
     * Mark an event as failed with error context.
     */
    public function markFailed(InboxEvent $inbox, string $errorMessage, string $errorCategory = 'unknown'): void
    {
        $inbox->update([
            'status'         => InboxEvent::STATUS_FAILED,
            'failed_at'      => now(),
            'error_message'  => mb_substr($errorMessage, 0, 5000),
            'error_category' => $errorCategory,
        ]);
    }

    /**
     * Reset an event for replay (used by DLQ replay command).
     */
    public function resetForReplay(string $eventId): ?InboxEvent
    {
        $inbox = InboxEvent::where('event_id', $eventId)->first();

        if ($inbox === null) {
            return null;
        }

        $inbox->update([
            'status'                => InboxEvent::STATUS_RECEIVED,
            'attempts'              => 0,
            'error_message'         => null,
            'error_category'        => null,
            'processing_started_at' => null,
            'processed_at'          => null,
            'failed_at'             => null,
        ]);

        return $inbox;
    }

    /**
     * Detect whether a QueryException is a duplicate-key violation.
     */
    private function isDuplicateKeyError(QueryException $e): bool
    {
        $code = (string) $e->errorInfo[1] ?? '';

        // SQLite: UNIQUE constraint failed (SQLSTATE 23000, driver code 19)
        // MySQL:  Duplicate entry (SQLSTATE 23000, driver code 1062)
        // PostgreSQL: unique_violation (SQLSTATE 23505)
        return in_array($e->errorInfo[0] ?? '', ['23000', '23505'], true)
            || in_array($code, ['19', '1062', '7'], true);
    }
}
