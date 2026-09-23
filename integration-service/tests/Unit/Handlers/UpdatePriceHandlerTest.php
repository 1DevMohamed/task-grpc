<?php

namespace Tests\Unit\Handlers;

use App\Handlers\UpdatePriceHandler;
use App\Integration\DTOs\EventEnvelope;
use App\Integration\Exceptions\PermanentFailureException;
use App\Integration\Exceptions\TransientFailureException;
use App\Services\VendorGrpcClientService;
use Mockery;
use PHPUnit\Framework\TestCase;

class UpdatePriceHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeEnvelope(array $payload = []): EventEnvelope
    {
        return EventEnvelope::fromArray([
            'event_id'       => 'evt-price-001',
            'event_type'     => 'product.price.updated',
            'aggregate_type' => 'product_variant',
            'aggregate_id'   => 'SKU-001',
            'correlation_id' => 'corr-001',
            'payload'        => array_merge([
                'vendor_code' => 'VENDOR-001',
                'variant_sku' => 'SKU-001',
                'price'       => 29.99,
            ], $payload),
        ]);
    }

    public function test_handles_valid_price_update(): void
    {
        $vendor = Mockery::mock(VendorGrpcClientService::class);
        $vendor->shouldReceive('updatePrice')
            ->once()
            ->with('VENDOR-001', 'SKU-001', 29.99, 'corr-001', 'evt-price-001');

        $handler = new UpdatePriceHandler($vendor);
        $handler->handle($this->makeEnvelope());

        $this->assertTrue(true); // No exception means success
    }

    public function test_passes_correlation_and_idempotency_key(): void
    {
        $vendor = Mockery::mock(VendorGrpcClientService::class);
        $vendor->shouldReceive('updatePrice')
            ->once()
            ->withArgs(function ($vc, $vs, $p, $corrId, $idempKey) {
                return $corrId === 'corr-001' && $idempKey === 'evt-price-001';
            });

        $handler = new UpdatePriceHandler($vendor);
        $handler->handle($this->makeEnvelope());

        $this->assertTrue(true);
    }

    public function test_rejects_missing_vendor_code(): void
    {
        $vendor = Mockery::mock(VendorGrpcClientService::class);
        $handler = new UpdatePriceHandler($vendor);

        $this->expectException(PermanentFailureException::class);
        $this->expectExceptionMessage('vendor_code');

        $handler->handle($this->makeEnvelope(['vendor_code' => '']));
    }

    public function test_rejects_missing_price(): void
    {
        $vendor = Mockery::mock(VendorGrpcClientService::class);
        $handler = new UpdatePriceHandler($vendor);

        $this->expectException(PermanentFailureException::class);
        $this->expectExceptionMessage('price');

        $handler->handle($this->makeEnvelope(['price' => null]));
    }

    public function test_rejects_negative_price(): void
    {
        $vendor = Mockery::mock(VendorGrpcClientService::class);
        $handler = new UpdatePriceHandler($vendor);

        $this->expectException(PermanentFailureException::class);
        $this->expectExceptionMessage('price must be >= 0');

        $handler->handle($this->makeEnvelope(['price' => -5.00]));
    }

    public function test_propagates_vendor_rpc_timeout(): void
    {
        $vendor = Mockery::mock(VendorGrpcClientService::class);
        $vendor->shouldReceive('updatePrice')
            ->once()
            ->andThrow(new TransientFailureException('Deadline exceeded'));

        $handler = new UpdatePriceHandler($vendor);

        $this->expectException(TransientFailureException::class);

        $handler->handle($this->makeEnvelope());
    }

    public function test_propagates_vendor_unavailable(): void
    {
        $vendor = Mockery::mock(VendorGrpcClientService::class);
        $vendor->shouldReceive('updatePrice')
            ->once()
            ->andThrow(new TransientFailureException('Service unavailable'));

        $handler = new UpdatePriceHandler($vendor);

        $this->expectException(TransientFailureException::class);

        $handler->handle($this->makeEnvelope());
    }

    public function test_propagates_vendor_business_error(): void
    {
        $vendor = Mockery::mock(VendorGrpcClientService::class);
        $vendor->shouldReceive('updatePrice')
            ->once()
            ->andThrow(new PermanentFailureException('Vendor not found'));

        $handler = new UpdatePriceHandler($vendor);

        $this->expectException(PermanentFailureException::class);

        $handler->handle($this->makeEnvelope());
    }

    public function test_supported_event_types(): void
    {
        $this->assertSame(['product.price.updated'], UpdatePriceHandler::supportedEventTypes());
    }
}
