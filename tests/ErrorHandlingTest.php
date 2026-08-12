<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;

use function assert;
use function is_string;
use function json_decode;
use function json_encode;

final class ErrorHandlingTest extends TestCase
{
    public function testFlushWithoutOpenReturnsEmptyLog(): void
    {
        $logger = new SemanticLogger();

        $log = $logger->flush();

        $this->assertSame(CoreSchema::LOG_URL, $log->schemaUrl);
        $this->assertSame([], $log->open);
        $this->assertSame([], $log->close);
        $this->assertSame([], $log->events);
    }

    public function testToArrayWithoutOpenReturnsEmptyShape(): void
    {
        $logger = new SemanticLogger();

        $array = $logger->toArray();

        $this->assertSame(CoreSchema::LOG_URL, $array['$schema']);
        $this->assertSame([], $array['open']);
    }

    public function testJsonSerializeWithoutOpenProducesEmptyLog(): void
    {
        $logger = new SemanticLogger();

        $json = json_encode($logger);
        assert(is_string($json));
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame(CoreSchema::LOG_URL, $decoded['$schema']);
        $this->assertSame([], $decoded['open']);
    }

    public function testCompleteLogSessionSerialization(): void
    {
        $logger = new SemanticLogger();

        // Open with nested structure
        $outerOpen = new FakeContext('outer', 1);
        $outerOpenId = $logger->open($outerOpen);

        $innerOpen = new FakeContext('inner', 2);
        $innerOpenId = $logger->open($innerOpen);

        // Add multiple events
        $event1 = new FakeContext('event1', 10);
        $logger->event($event1);

        $event2 = new FakeContext('event2', 20);
        $logger->event($event2);

        // Close inner first, then outer (LIFO order)
        $innerClose = new FakeContext('inner_finished', 998);
        $logger->close($innerClose, $innerOpenId);

        $outerClose = new FakeContext('outer_finished', 999);
        $logger->close($outerClose, $outerOpenId);

        // Test via flush() method
        $logJson = $logger->flush();

        // Verify structure
        $this->assertSame(CoreSchema::LOG_URL, $logJson->schemaUrl);

        // Test nested structure
        $this->assertSame('outer', $logJson->open[0]->context['message']);
        $this->assertCount(1, $logJson->open[0]->open);
        $this->assertSame('inner', $logJson->open[0]->open[0]->context['message']);

        // Test events
        $this->assertCount(2, $logJson->events);
        $this->assertSame('event1', $logJson->events[0]->context['message']);
        $this->assertSame('event2', $logJson->events[1]->context['message']);

        // Test close (should be outer close since it's the root operation)
        $this->assertSame('outer_finished', $logJson->close[0]->context['message']);

        // The logger is cleared after flush: a second flush returns an empty log.
        $next = $logger->flush();
        $this->assertSame([], $next->open);
        $this->assertSame([], $next->events);
    }
}
