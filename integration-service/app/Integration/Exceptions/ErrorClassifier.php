<?php

namespace App\Integration\Exceptions;

use Throwable;

/**
 * Classifies exceptions to determine retry behavior.
 *
 * Categories:
 * - PERMANENT: Business/validation errors — do NOT retry
 * - TRANSIENT: Timeout/unavailable errors — retry with backoff
 * - UNKNOWN:   Unexpected errors — retry with caution, log full context
 */
class ErrorClassifier
{
    public const string PERMANENT = 'permanent';
    public const string TRANSIENT = 'transient';
    public const string UNKNOWN   = 'unknown';

    /**
     * gRPC status codes that indicate transient failures.
     *
     * @see https://grpc.github.io/grpc/core/md_doc_statuscodes.html
     */
    private const array TRANSIENT_GRPC_CODES = [
        1,  // CANCELLED
        4,  // DEADLINE_EXCEEDED
        8,  // RESOURCE_EXHAUSTED
        10, // ABORTED
        14, // UNAVAILABLE
    ];

    /**
     * gRPC status codes that indicate permanent failures.
     */
    private const array PERMANENT_GRPC_CODES = [
        3,  // INVALID_ARGUMENT
        5,  // NOT_FOUND
        6,  // ALREADY_EXISTS
        7,  // PERMISSION_DENIED
        9,  // FAILED_PRECONDITION
        12, // UNIMPLEMENTED
        16, // UNAUTHENTICATED
    ];

    /**
     * Classify an exception into a retry category.
     */
    public function classify(Throwable $e): string
    {
        // Already classified exceptions
        if ($e instanceof PermanentFailureException) {
            return self::PERMANENT;
        }

        if ($e instanceof TransientFailureException) {
            return self::TRANSIENT;
        }

        // gRPC error classification based on message patterns
        $message = strtolower($e->getMessage());

        // Check for transient indicators
        if ($this->isTransientMessage($message)) {
            return self::TRANSIENT;
        }

        // Check for permanent indicators
        if ($this->isPermanentMessage($message)) {
            return self::PERMANENT;
        }

        // Connection / network errors are transient
        if ($e instanceof \RuntimeException && $this->isConnectionError($message)) {
            return self::TRANSIENT;
        }

        return self::UNKNOWN;
    }

    /**
     * Whether the classified error should be retried.
     */
    public function shouldRetry(Throwable $e): bool
    {
        $category = $this->classify($e);

        return match ($category) {
            self::PERMANENT => false,
            self::TRANSIENT => true,
            self::UNKNOWN   => true, // Retry unknowns with caution
        };
    }

    /**
     * Wrap a raw exception into a typed failure exception.
     */
    public function wrapException(Throwable $e): PermanentFailureException|TransientFailureException
    {
        $category = $this->classify($e);

        return match ($category) {
            self::PERMANENT => new PermanentFailureException(
                message: $e->getMessage(),
                previous: $e,
                errorCategory: 'business_error',
            ),
            default => new TransientFailureException(
                message: $e->getMessage(),
                previous: $e,
                errorCategory: $category === self::TRANSIENT ? 'transient_error' : 'unknown_error',
            ),
        };
    }

    private function isTransientMessage(string $message): bool
    {
        $patterns = [
            'deadline exceeded',
            'timeout',
            'timed out',
            'unavailable',
            'connection refused',
            'connection reset',
            'broken pipe',
            'resource exhausted',
            'too many requests',
            'temporarily unavailable',
            'service unavailable',
            'connect failed',
            'dns resolution failed',
            'name resolution failed',
            'network is unreachable',
            'aborted',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isPermanentMessage(string $message): bool
    {
        $patterns = [
            'not found',
            'invalid argument',
            'validation fail',
            'already exists',
            'permission denied',
            'unauthenticated',
            'unimplemented',
            'failed precondition',
            'vendor not found',
            'product not found',
            'variant not found',
            'invalid vendor',
            'invalid product',
            'invalid sku',
            'invalid uom',
            'cannot resolve ids',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isConnectionError(string $message): bool
    {
        $patterns = [
            'connection',
            'socket',
            'curl',
            'ssl',
            'tls',
            'handshake',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
