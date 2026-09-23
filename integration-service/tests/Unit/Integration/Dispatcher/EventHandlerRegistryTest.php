<?php

namespace Tests\Unit\Integration\Dispatcher;

use App\Integration\Contracts\EventHandlerInterface;
use App\Integration\Dispatcher\EventHandlerRegistry;
use App\Integration\DTOs\EventEnvelope;
use Illuminate\Container\Container;
use Tests\TestCase;

class EventHandlerRegistryTest extends TestCase
{
    private EventHandlerRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new EventHandlerRegistry(Container::getInstance());
    }

    public function test_resolves_registered_handler(): void
    {
        $handler = new class implements EventHandlerInterface {
            public function handle(EventEnvelope $envelope): void {}
            public static function supportedEventTypes(): array { return ['test.event']; }
        };

        $this->registry->register(get_class($handler), ['test.event']);

        Container::getInstance()->instance(get_class($handler), $handler);

        $resolved = $this->registry->resolve('test.event');

        $this->assertNotNull($resolved);
        $this->assertInstanceOf(EventHandlerInterface::class, $resolved);
    }

    public function test_returns_null_for_unknown_event(): void
    {
        $this->assertNull($this->registry->resolve('unknown.event'));
    }

    public function test_has_returns_true_for_registered(): void
    {
        $handler = new class implements EventHandlerInterface {
            public function handle(EventEnvelope $envelope): void {}
            public static function supportedEventTypes(): array { return ['test.event']; }
        };

        $this->registry->register(get_class($handler), ['test.event']);

        $this->assertTrue($this->registry->has('test.event'));
    }

    public function test_has_returns_false_for_unregistered(): void
    {
        $this->assertFalse($this->registry->has('unknown.event'));
    }

    public function test_registers_multiple_event_types(): void
    {
        $handler = new class implements EventHandlerInterface {
            public function handle(EventEnvelope $envelope): void {}
            public static function supportedEventTypes(): array { return ['event.a', 'event.b']; }
        };

        $this->registry->register(get_class($handler), ['event.a', 'event.b']);

        $this->assertTrue($this->registry->has('event.a'));
        $this->assertTrue($this->registry->has('event.b'));
    }

    public function test_registered_event_types_lists_all(): void
    {
        $handler = new class implements EventHandlerInterface {
            public function handle(EventEnvelope $envelope): void {}
            public static function supportedEventTypes(): array { return ['event.x']; }
        };

        $this->registry->register(get_class($handler), ['event.x', 'event.y']);

        $types = $this->registry->registeredEventTypes();

        $this->assertContains('event.x', $types);
        $this->assertContains('event.y', $types);
    }
}
