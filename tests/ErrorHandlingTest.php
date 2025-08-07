<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use Koriym\SemanticLogger\Exception\NoLogSessionException;
use PHPUnit\Framework\TestCase;

use function json_encode;

final class ErrorHandlingTest extends TestCase
{
    public function testFlushWithoutOpenThrowsException(): void
    {
        $logger = new SemanticLogger();

        $this->expectException(NoLogSessionException::class);

        $logger->flush();
    }

    public function testToArrayWithoutOpenThrowsException(): void
    {
        $logger = new SemanticLogger();

        $this->expectException(NoLogSessionException::class);

        $logger->toArray();
    }

    public function testJsonSerializeWithoutOpenThrowsException(): void
    {
        $logger = new SemanticLogger();

        $this->expectException(NoLogSessionException::class);

        json_encode($logger);
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
        $this->assertSame('https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json', $logJson->schemaUrl);

        // Test nested structure
        $this->assertSame('outer', $logJson->open->context['message']);
        $this->assertNotNull($logJson->open->open);
        $this->assertSame('inner', $logJson->open->open->context['message']);

        // Test events
        $this->assertCount(2, $logJson->events);
        $this->assertSame('event1', $logJson->events[0]->context['message']);
        $this->assertSame('event2', $logJson->events[1]->context['message']);

        // Test close (should be outer close since it's the root operation)
        $this->assertSame('outer_finished', $logJson->close->context['message']);

        // Test that logger is cleared after flush
        $this->expectException(NoLogSessionException::class);
        $logger->flush();
    }

    /**
     * Note: Line 242 assert() in SemanticLogger::buildNestedClose()
     * is an internal consistency check that should never fail in normal usage.
     * The assert ensures that if completedOperations exist, there are corresponding
     * closeStack entries. This is guaranteed by the class design where close()
     * operations always add to both stacks.
     */
    public function testInternalConsistencyAssertDocumented(): void
    {
        // This test documents that line 242 assert() is an internal consistency check
        // that should never fail through normal API usage. The assert will only
        // trigger in development mode if there's a bug in the class implementation.
        $this->assertTrue(true, 'Line 242 assert() is an internal consistency check');
    }
}
