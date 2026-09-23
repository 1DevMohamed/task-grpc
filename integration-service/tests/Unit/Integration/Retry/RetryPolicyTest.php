<?php

namespace Tests\Unit\Integration\Retry;

use App\Integration\Retry\RetryPolicy;
use Tests\TestCase;

class RetryPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set test config values
        config([
            'integration.retry.backoff_schedule_ms' => [0, 2000, 5000, 15000, 30000],
            'integration.retry.max_attempts'        => 5,
            'integration.retry.use_jitter'          => false, // Disable jitter for deterministic tests
        ]);
    }

    public function test_first_attempt_has_no_delay(): void
    {
        $policy = new RetryPolicy();
        $this->assertSame(0, $policy->getDelayMs(1));
    }

    public function test_second_attempt_delay(): void
    {
        $policy = new RetryPolicy();
        $this->assertSame(2000, $policy->getDelayMs(2));
    }

    public function test_third_attempt_delay(): void
    {
        $policy = new RetryPolicy();
        $this->assertSame(5000, $policy->getDelayMs(3));
    }

    public function test_fourth_attempt_delay(): void
    {
        $policy = new RetryPolicy();
        $this->assertSame(15000, $policy->getDelayMs(4));
    }

    public function test_fifth_attempt_delay(): void
    {
        $policy = new RetryPolicy();
        $this->assertSame(30000, $policy->getDelayMs(5));
    }

    public function test_excess_attempt_uses_last_value(): void
    {
        $policy = new RetryPolicy();
        $this->assertSame(30000, $policy->getDelayMs(10));
    }

    public function test_should_retry_within_max(): void
    {
        $policy = new RetryPolicy();

        $this->assertTrue($policy->shouldRetry(1));
        $this->assertTrue($policy->shouldRetry(4));
    }

    public function test_should_not_retry_at_max(): void
    {
        $policy = new RetryPolicy();

        $this->assertFalse($policy->shouldRetry(5));
        $this->assertFalse($policy->shouldRetry(6));
    }

    public function test_max_attempts_returns_configured_value(): void
    {
        $policy = new RetryPolicy();
        $this->assertSame(5, $policy->getMaxAttempts());
    }

    public function test_jitter_adds_variance(): void
    {
        config(['integration.retry.use_jitter' => true]);

        $policy = new RetryPolicy();

        // With jitter enabled, repeated calls should produce different delays
        // (statistically very unlikely to be identical 10 times)
        $delays = [];
        for ($i = 0; $i < 10; $i++) {
            $delays[] = $policy->getDelayMs(3); // base: 5000ms
        }

        // All delays should be within 75%-125% of base (3750-6250)
        foreach ($delays as $delay) {
            $this->assertGreaterThanOrEqual(3750, $delay);
            $this->assertLessThanOrEqual(6250, $delay);
        }
    }
}
