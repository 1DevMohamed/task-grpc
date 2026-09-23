<?php

namespace Tests\Unit\Integration\Exceptions;

use App\Integration\Exceptions\ErrorClassifier;
use App\Integration\Exceptions\PermanentFailureException;
use App\Integration\Exceptions\TransientFailureException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ErrorClassifierTest extends TestCase
{
    private ErrorClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new ErrorClassifier();
    }

    public function test_classifies_permanent_failure_exception(): void
    {
        $e = new PermanentFailureException('Invalid vendor');
        $this->assertSame(ErrorClassifier::PERMANENT, $this->classifier->classify($e));
        $this->assertFalse($this->classifier->shouldRetry($e));
    }

    public function test_classifies_transient_failure_exception(): void
    {
        $e = new TransientFailureException('Service unavailable');
        $this->assertSame(ErrorClassifier::TRANSIENT, $this->classifier->classify($e));
        $this->assertTrue($this->classifier->shouldRetry($e));
    }

    public function test_classifies_timeout_as_transient(): void
    {
        $e = new RuntimeException('Deadline exceeded for RPC call');
        $this->assertSame(ErrorClassifier::TRANSIENT, $this->classifier->classify($e));
    }

    public function test_classifies_unavailable_as_transient(): void
    {
        $e = new RuntimeException('Service unavailable');
        $this->assertSame(ErrorClassifier::TRANSIENT, $this->classifier->classify($e));
    }

    public function test_classifies_connection_refused_as_transient(): void
    {
        $e = new RuntimeException('Connection refused to vendor-svc:50054');
        $this->assertSame(ErrorClassifier::TRANSIENT, $this->classifier->classify($e));
    }

    public function test_classifies_not_found_as_permanent(): void
    {
        $e = new RuntimeException('Vendor not found: INVALID-001');
        $this->assertSame(ErrorClassifier::PERMANENT, $this->classifier->classify($e));
    }

    public function test_classifies_validation_failure_as_permanent(): void
    {
        $e = new RuntimeException('Validation failed for price field');
        $this->assertSame(ErrorClassifier::PERMANENT, $this->classifier->classify($e));
    }

    public function test_classifies_cannot_resolve_ids_as_permanent(): void
    {
        $e = new RuntimeException('Cannot resolve IDs for VENDOR/SKU');
        $this->assertSame(ErrorClassifier::PERMANENT, $this->classifier->classify($e));
    }

    public function test_classifies_generic_exception_as_unknown(): void
    {
        $e = new RuntimeException('Something completely unexpected');
        $this->assertSame(ErrorClassifier::UNKNOWN, $this->classifier->classify($e));
    }

    public function test_unknown_errors_are_retried(): void
    {
        $e = new RuntimeException('Something unexpected');
        $this->assertTrue($this->classifier->shouldRetry($e));
    }

    public function test_wraps_transient_error_correctly(): void
    {
        $original = new RuntimeException('Connection refused');
        $wrapped  = $this->classifier->wrapException($original);

        $this->assertInstanceOf(TransientFailureException::class, $wrapped);
        $this->assertSame($original, $wrapped->getPrevious());
    }

    public function test_wraps_permanent_error_correctly(): void
    {
        $original = new RuntimeException('Vendor not found: BAD');
        $wrapped  = $this->classifier->wrapException($original);

        $this->assertInstanceOf(PermanentFailureException::class, $wrapped);
        $this->assertSame($original, $wrapped->getPrevious());
    }

    public function test_wraps_unknown_error_as_transient(): void
    {
        $original = new RuntimeException('Strange unknown error');
        $wrapped  = $this->classifier->wrapException($original);

        // Unknowns are wrapped as transient (retry with caution)
        $this->assertInstanceOf(TransientFailureException::class, $wrapped);
    }
}
