<?php

namespace Tests\Unit\Integration\Dispatcher;

use App\Integration\Contracts\EventHandlerInterface;
use App\Integration\Dispatcher\EventHandlerRegistry;
use App\Integration\Dispatcher\MessageDispatcher;
use App\Integration\DTOs\EventEnvelope;
use App\Integration\Exceptions\PermanentFailureException;
use App\Integration\Logging\IntegrationLogger;
use Illuminate\Container\Container;
use Tests\TestCase;

class MessageDispatcherTest extends TestCase
{
    private function makeEnvelope(string $eventType = 'product.price.updated'): EventEnvelope
    {
        return EventEnvelope::fromArray([
            'event_id'       => 'evt-test-001',
            'event_type'     => $eventType,
            'aggregate_type' => 'product_variant',
            'aggregate_id'   => 'SKU-001',
            'correlation_id' => 'corr-001',
            'payload'        => ['vendor_code' => 'V001', 'variant_sku' => 'SKU-001', 'price' => 10.00],
        ]);
    }

    public function test_dispatches_to_correct_handler(): void
    {
        $called = false;

        $handler = new class($called) implements EventHandlerInterface {
            public function __construct(private bool &$called) {}
            public function handle(EventEnvelope $envelope): void { $this->called = true; }
            public static function supportedEventTypes(): array { return ['product.price.updated']; }
        };

        $container = Container::getInstance();
        $container->instance(get_class($handler), $handler);

        $registry = new EventHandlerRegistry($container);
        $registry->register(get_class($handler), ['product.price.updated']);

        $logger     = new IntegrationLogger();
        $dispatcher = new MessageDispatcher($registry, $logger);

        $dispatcher->dispatch($this->makeEnvelope('product.price.updated'));

        $this->assertTrue($called);
    }

    public function test_throws_permanent_failure_for_unknown_event_type(): void
    {
        $container  = Container::getInstance();
        $registry   = new EventHandlerRegistry($container);
        $logger     = new IntegrationLogger();
        $dispatcher = new MessageDispatcher($registry, $logger);

        $this->expectException(PermanentFailureException::class);
        $this->expectExceptionMessage('No handler registered');

        $dispatcher->dispatch($this->makeEnvelope('totally.unknown.event'));
    }

    public function test_propagates_handler_exceptions(): void
    {
        $handler = new class implements EventHandlerInterface {
            public function handle(EventEnvelope $envelope): void {
                throw new \RuntimeException('Handler exploded');
            }
            public static function supportedEventTypes(): array { return ['test.event']; }
        };

        $container = Container::getInstance();
        $container->instance(get_class($handler), $handler);

        $registry = new EventHandlerRegistry($container);
        $registry->register(get_class($handler), ['test.event']);

        $logger     = new IntegrationLogger();
        $dispatcher = new MessageDispatcher($registry, $logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Handler exploded');

        $dispatcher->dispatch($this->makeEnvelope('test.event'));
    }
}
