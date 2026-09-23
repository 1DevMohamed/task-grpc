<?php

namespace Tests\Unit\Handlers;

use App\Grpc\Vendor\ResolveIdsResponse;
use App\Handlers\UpdateStockHandler;
use App\Integration\DTOs\EventEnvelope;
use App\Integration\Exceptions\PermanentFailureException;
use App\Integration\Exceptions\TransientFailureException;
use App\Services\InventoryGrpcClientService;
use App\Services\VendorGrpcClientService;
use Mockery;
use PHPUnit\Framework\TestCase;

class UpdateStockHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeEnvelope(array $payload = []): EventEnvelope
    {
        return EventEnvelope::fromArray([
            'event_id'       => 'evt-stock-001',
            'event_type'     => 'product.stock.updated',
            'aggregate_type' => 'product_variant',
            'aggregate_id'   => 'SKU-001',
            'correlation_id' => 'corr-001',
            'payload'        => array_merge([
                'vendor_code' => 'VENDOR-001',
                'variant_sku' => 'SKU-001',
                'quantity'    => 50,
            ], $payload),
        ]);
    }

    private function mockResolveResponse(int $productId = 101): ResolveIdsResponse
    {
        $response = Mockery::mock(ResolveIdsResponse::class);
        $response->shouldReceive('getInternalProductId')->andReturn($productId);
        $response->shouldReceive('getInternalVendorId')->andReturn(1);
        return $response;
    }

    public function test_handles_valid_stock_update(): void
    {
        $vendor    = Mockery::mock(VendorGrpcClientService::class);
        $inventory = Mockery::mock(InventoryGrpcClientService::class);

        $vendor->shouldReceive('resolveIds')
            ->once()
            ->with('VENDOR-001', 'SKU-001', 'corr-001', 'evt-stock-001')
            ->andReturn($this->mockResolveResponse(101));

        $inventory->shouldReceive('setAbsoluteStock')
            ->once()
            ->with(101, 50, 'corr-001', 'evt-stock-001');

        $handler = new UpdateStockHandler($vendor, $inventory);
        $handler->handle($this->makeEnvelope());

        $this->assertTrue(true);
    }

    public function test_rejects_missing_quantity(): void
    {
        $vendor    = Mockery::mock(VendorGrpcClientService::class);
        $inventory = Mockery::mock(InventoryGrpcClientService::class);

        $handler = new UpdateStockHandler($vendor, $inventory);

        $this->expectException(PermanentFailureException::class);
        $this->expectExceptionMessage('quantity');

        $handler->handle($this->makeEnvelope(['quantity' => null]));
    }

    public function test_rejects_missing_vendor_code(): void
    {
        $vendor    = Mockery::mock(VendorGrpcClientService::class);
        $inventory = Mockery::mock(InventoryGrpcClientService::class);

        $handler = new UpdateStockHandler($vendor, $inventory);

        $this->expectException(PermanentFailureException::class);
        $this->expectExceptionMessage('vendor_code');

        $handler->handle($this->makeEnvelope(['vendor_code' => '']));
    }

    public function test_propagates_vendor_resolve_failure(): void
    {
        $vendor    = Mockery::mock(VendorGrpcClientService::class);
        $inventory = Mockery::mock(InventoryGrpcClientService::class);

        $vendor->shouldReceive('resolveIds')
            ->once()
            ->andThrow(new PermanentFailureException('Cannot resolve IDs'));

        $handler = new UpdateStockHandler($vendor, $inventory);

        $this->expectException(PermanentFailureException::class);

        $handler->handle($this->makeEnvelope());
    }

    public function test_vendor_success_inventory_failure(): void
    {
        $vendor    = Mockery::mock(VendorGrpcClientService::class);
        $inventory = Mockery::mock(InventoryGrpcClientService::class);

        // RPC #1 succeeds
        $vendor->shouldReceive('resolveIds')
            ->once()
            ->andReturn($this->mockResolveResponse(101));

        // RPC #2 fails
        $inventory->shouldReceive('setAbsoluteStock')
            ->once()
            ->andThrow(new TransientFailureException('Inventory service unavailable'));

        $handler = new UpdateStockHandler($vendor, $inventory);

        // Should propagate transient failure for retry
        // On retry, RPC #1 is safe (read-only/idempotent)
        $this->expectException(TransientFailureException::class);

        $handler->handle($this->makeEnvelope());
    }

    public function test_vendor_timeout(): void
    {
        $vendor    = Mockery::mock(VendorGrpcClientService::class);
        $inventory = Mockery::mock(InventoryGrpcClientService::class);

        $vendor->shouldReceive('resolveIds')
            ->once()
            ->andThrow(new TransientFailureException('Deadline exceeded'));

        // Inventory should NOT be called
        $inventory->shouldNotReceive('setAbsoluteStock');

        $handler = new UpdateStockHandler($vendor, $inventory);

        $this->expectException(TransientFailureException::class);

        $handler->handle($this->makeEnvelope());
    }

    public function test_inventory_timeout(): void
    {
        $vendor    = Mockery::mock(VendorGrpcClientService::class);
        $inventory = Mockery::mock(InventoryGrpcClientService::class);

        $vendor->shouldReceive('resolveIds')
            ->once()
            ->andReturn($this->mockResolveResponse(101));

        $inventory->shouldReceive('setAbsoluteStock')
            ->once()
            ->andThrow(new TransientFailureException('Deadline exceeded'));

        $handler = new UpdateStockHandler($vendor, $inventory);

        $this->expectException(TransientFailureException::class);

        $handler->handle($this->makeEnvelope());
    }

    public function test_supported_event_types(): void
    {
        $this->assertSame(['product.stock.updated'], UpdateStockHandler::supportedEventTypes());
    }
}
