<?php

namespace App\Integration\Retry;

/**
 * Exponential backoff retry policy with optional jitter.
 *
 * This handles application-level retry delays WITHIN a single SQS message
 * processing attempt. The SQS visibility timeout and maxReceiveCount
 * provide the outer retry boundary.
 *
 * Default schedule (configurable):
 *   Attempt 1: immediate (0ms)
 *   Attempt 2: 2,000ms
 *   Attempt 3: 5,000ms
 *   Attempt 4: 15,000ms
 *   Attempt 5: 30,000ms
 */
class RetryPolicy
{
    /** @var int[] Delay in milliseconds for each attempt (0-indexed) */
    private readonly array $backoffSchedule;

    private readonly int $maxAttempts;

    private readonly bool $useJitter;

    public function __construct()
    {
        $config = config('integration.retry', []);

        $this->backoffSchedule = $config['backoff_schedule_ms'] ?? [0, 2000, 5000, 15000, 30000];
        $this->maxAttempts     = $config['max_attempts'] ?? 5;
        $this->useJitter       = $config['use_jitter'] ?? true;
    }

    /**
     * Get the delay in milliseconds for the given attempt number (1-based).
     */
    public function getDelayMs(int $attempt): int
    {
        $index = max(0, $attempt - 1);

        // If attempt exceeds schedule, use the last value
        if (isset($this->backoffSchedule[$index])) {
            $baseDelay = $this->backoffSchedule[$index];
        } elseif (!empty($this->backoffSchedule)) {
            $lastIndex = array_key_last($this->backoffSchedule);
            $baseDelay = $this->backoffSchedule[$lastIndex] ?? 0;
        } else {
            $baseDelay = 0;
        }

        if ($this->useJitter && $baseDelay > 0) {
            // Add jitter: 75% to 125% of base delay
            $jitterFactor = 0.75 + (mt_rand(0, 500) / 1000);
            $baseDelay    = (int) ($baseDelay * $jitterFactor);
        }

        return max(0, $baseDelay);
    }

    /**
     * Whether the given attempt number should be retried.
     */
    public function shouldRetry(int $attempt): bool
    {
        return $attempt < $this->maxAttempts;
    }

    /**
     * Apply the delay for the given attempt (blocking).
     */
    public function wait(int $attempt): void
    {
        $delayMs = $this->getDelayMs($attempt);

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }
}
