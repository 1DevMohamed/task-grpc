<?php

namespace App\Integration\Services;

/**
 * Immutable result of event processing.
 *
 * Tells the SQS consumer whether to delete (acknowledge) the message
 * or leave it for retry.
 */
final readonly class ProcessingResult
{
    private function __construct(
        public bool    $shouldAcknowledge,
        public string  $status,
        public ?string $message = null,
    ) {}

    /**
     * Event was processed successfully — delete SQS message.
     */
    public static function success(): self
    {
        return new self(shouldAcknowledge: true, status: 'success');
    }

    /**
     * Event was already processed (duplicate) — delete SQS message.
     */
    public static function alreadyProcessed(): self
    {
        return new self(shouldAcknowledge: true, status: 'already_processed');
    }

    /**
     * Permanent failure — delete SQS message (will move to DLQ via maxReceiveCount).
     */
    public static function permanentFailure(string $message): self
    {
        return new self(shouldAcknowledge: false, status: 'permanent_failure', message: $message);
    }

    /**
     * Transient failure — do NOT delete SQS message, allow SQS retry.
     */
    public static function transientFailure(string $message): self
    {
        return new self(shouldAcknowledge: false, status: 'transient_failure', message: $message);
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success' || $this->status === 'already_processed';
    }
}
