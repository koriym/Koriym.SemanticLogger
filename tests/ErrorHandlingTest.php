<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;

use function array_map;
use function assert;
use function is_array;
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
        $this->assertSame([], $log->records);
        $this->assertSame([], $log->toArray()['log']);
    }

    public function testToArrayWithoutOpenReturnsEmptyShape(): void
    {
        $logger = new SemanticLogger();

        $array = $logger->toArray();

        $this->assertSame(CoreSchema::LOG_URL, $array['$schema']);
        $this->assertSame([], $array['log']);
    }

    public function testJsonSerializeWithoutOpenProducesEmptyLog(): void
    {
        $logger = new SemanticLogger();

        $json = json_encode($logger);
        assert(is_string($json));
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame(CoreSchema::LOG_URL, $decoded['$schema']);
        $this->assertSame([], $decoded['log']);
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

        $array = $logJson->toArray();
        $this->assertCount(1, $array['log']);

        // Outer open: nested inner open, then closed by the outer close.
        $outer = $array['log'][0];
        $this->assertTrue($this->isIntervalShaped($outer));
        $this->assertSame('outer', $this->messageOf($outer));
        $this->assertSame('outer_finished', $this->messageOf($outer['close'] ?? []));
        $outerChildren = $outer['log'] ?? [];
        $this->assertCount(1, $outerChildren);

        // The inner open holds both events in record order, then its own close.
        $inner = $outerChildren[0];
        $this->assertTrue($this->isIntervalShaped($inner));
        $this->assertSame('inner', $this->messageOf($inner));
        $innerClose = $inner['close'] ?? [];
        $this->assertIsArray($innerClose);
        $this->assertSame('inner_finished', $this->messageOf($innerClose));
        $innerChildren = $inner['log'] ?? [];
        $this->assertIsArray($innerChildren);
        $this->assertCount(2, $innerChildren);
        $shapes = array_map(
            fn (mixed $entry): bool => is_array($entry) && $this->isIntervalShaped($entry),
            $innerChildren,
        );
        $this->assertSame([false, false], $shapes);
        $this->assertIsArray($innerChildren[0]);
        $this->assertIsArray($innerChildren[1]);
        $this->assertSame('event1', $this->messageOf($innerChildren[0]));
        $this->assertSame('event2', $this->messageOf($innerChildren[1]));

        // The logger is cleared after flush: a second flush returns an empty log.
        $next = $logger->flush();
        $this->assertSame([], $next->records);
    }

    /** @param array<mixed> $entry */
    private function messageOf(array $entry): mixed
    {
        $context = $entry['context'] ?? null;
        $this->assertIsArray($context);

        return $context['message'] ?? null;
    }

    /**
     * Structural kind: an interval carries `close` or `log`, a point carries neither.
     *
     * @param array<array-key, mixed> $entry
     */
    private function isIntervalShaped(array $entry): bool
    {
        return isset($entry['close']) || isset($entry['log']);
    }
}
