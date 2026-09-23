<?php

namespace App\Integration\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Represents a transient/retryable error.
 *
 * Examples:
 * - RPC timeout
 * - Service unavailable
 * - Connection refused
 * - Temporary network failure
 * - Database deadlock
 *
 * When this exception is thrown, the event should be retried with
 * exponential backoff. After max retries it moves to DLQ.
 */
class TransientFailureException extends RuntimeException
{
    public function __construct(
        string     $message = 'Transient failure — will retry',
        int        $code = 0,
        ?Throwable $previous = null,
        public readonly string $errorCategory = 'transient_error',
    ) {
        parent::__construct($message, $code, $previous);
    }
}
