<?php

namespace App\Integration\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Represents a permanent/business error that should NOT be retried.
 *
 * Examples:
 * - Invalid vendor code
 * - Unknown product/variant
 * - Validation failure from a domain service
 * - Unsupported operation
 *
 * When this exception is thrown, the event should be recorded as FAILED
 * and moved toward the DLQ without further retries.
 */
class PermanentFailureException extends RuntimeException
{
    public function __construct(
        string     $message = 'Permanent failure — will not retry',
        int        $code = 0,
        ?Throwable $previous = null,
        public readonly string $errorCategory = 'business_error',
    ) {
        parent::__construct($message, $code, $previous);
    }
}
