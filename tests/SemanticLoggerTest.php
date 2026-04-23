<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use Koriym\SemanticLogger\Exception\NoLogSessionException;
use Koriym\SemanticLogger\Exception\UnclosedLogicException;
use LogicException;
use PHPUnit\Framework\TestCase;

use function assert;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function substr_count;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;

final class SemanticLoggerTest extends TestCase
{
    private SemanticLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new SemanticLogger();
    }

    public function testBasicFlow(): void
    {
        // Open
        $openContext = new class ('starting process', 1) extends AbstractContext {
            public const TYPE = 'process_start';
            public const SCHEMA_URL = 'https://example.com/schemas/process_start.json';

            public function __construct(
                public readonly string $message,
                public readonly int $id,
            ) {
            }
        };

        $openId = $this->logger->open($openContext);

        // Event
        $eventContext = new FakeContext('processing data', 42);
        $this->logger->event($eventContext);

        // Close
        $closeContext = new class ('completed successfully') extends AbstractContext {
            public const TYPE = 'process_complete';
            public const SCHEMA_URL = 'https://example.com/schemas/process_complete.json';

            public function __construct(
                public readonly string $result,
            ) {
            }
        };

        $this->logger->close($closeContext, $openId);

        $logJson = $this->logger->flush();

        $this->assertSame('https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json', $logJson->schemaUrl);

        // Open - check ID
        $this->assertCount(1, $logJson->open);
        $this->assertSame('process_start_1', $logJson->open[0]->id);
        $this->assertSame('process_start', $logJson->open[0]->type);
        $this->assertSame('https://example.com/schemas/process_start.json', $logJson->open[0]->schemaUrl);
        $this->assertSame('starting process', $logJson->open[0]->context['message']);
        $this->assertSame(1, $logJson->open[0]->context['id']);

        // Events - check ID
        $this->assertCount(1, $logJson->events);
        $this->assertSame('example_event_1', $logJson->events[0]->id);
        $this->assertSame('example_event', $logJson->events[0]->type);
        $this->assertSame('https://example.com/schemas/example.json', $logJson->events[0]->schemaUrl);
        $this->assertSame('processing data', $logJson->events[0]->context['message']);
        $this->assertSame(42, $logJson->events[0]->context['value']);

        // Close - check ID
        $this->assertCount(1, $logJson->close);
        $this->assertSame('process_complete_1', $logJson->close[0]->id);
        $this->assertSame('process_complete', $logJson->close[0]->type);
        $this->assertSame('https://example.com/schemas/process_complete.json', $logJson->close[0]->schemaUrl);
        $this->assertSame('completed successfully', $logJson->close[0]->context['result']);

        $serialized = $logJson->toArray();
        $root = $this->serializedOpenEntries($serialized)[0];
        $events = $this->serializedEntryEvents($root);
        $close = $this->serializedEntryClose($root);

        $this->assertArrayNotHasKey('events', $serialized);
        $this->assertArrayNotHasKey('close', $serialized);
        $this->assertCount(1, $events);
        $this->assertSame('example_event_1', $events[0]['id']);
        $this->assertSame('process_complete_1', $close['id']);
    }

    public function testSequentialSiblingsAreRecordedAsSiblings(): void
    {
        // open_1, close_1, open_2, close_2 at the top level — sequential siblings.
        // Regression for #24: buildNestedOpen() previously reconstructed a fake
        // nesting from close order alone, wrapping open_2 around open_1.
        $first = $this->logger->open(new FakeContext('first', 1));
        $this->logger->close(new FakeContext('first done', 2), $first);

        $second = $this->logger->open(new FakeContext('second', 3));
        $this->logger->close(new FakeContext('second done', 4), $second);

        $logJson = $this->logger->flush();

        $this->assertCount(2, $logJson->open, 'top-level opens should be siblings, not nested');
        $this->assertSame($first, $logJson->open[0]->id);
        $this->assertSame('first', $logJson->open[0]->context['message']);
        $this->assertSame([], $logJson->open[0]->open);

        $this->assertSame($second, $logJson->open[1]->id);
        $this->assertSame('second', $logJson->open[1]->context['message']);
        $this->assertSame([], $logJson->open[1]->open);

        $this->assertCount(2, $logJson->close);
        $this->assertSame($first, $logJson->close[0]->openId);
        $this->assertSame($second, $logJson->close[1]->openId);
    }

    public function testOuterWithTwoSiblingInnersKeepsRealOrder(): void
    {
        // outer { inner_1; inner_2 } — one parent containing two sibling children,
        // which is exactly the Be Framework metamorphosis-chain shape once the
        // chain wrapper lands on the framework side.
        $outer = $this->logger->open(new FakeContext('outer', 1));

        $inner1 = $this->logger->open(new FakeContext('inner1', 2));
        $this->logger->close(new FakeContext('inner1 done', 3), $inner1);

        $inner2 = $this->logger->open(new FakeContext('inner2', 4));
        $this->logger->close(new FakeContext('inner2 done', 5), $inner2);

        $this->logger->close(new FakeContext('outer done', 6), $outer);

        $logJson = $this->logger->flush();

        $this->assertCount(1, $logJson->open);
        $root = $logJson->open[0];
        $this->assertSame($outer, $root->id);

        $this->assertCount(2, $root->open, 'outer should list both inners as siblings in order');
        $this->assertSame($inner1, $root->open[0]->id);
        $this->assertSame($inner2, $root->open[1]->id);
    }

    public function testNestedOpen(): void
    {
        // First open
        $firstOpen = new FakeContext('outer process', 1);
        $firstOpenId = $this->logger->open($firstOpen);

        // Nested open
        $nestedOpen = new FakeContext('inner process', 2);
        $nestedOpenId = $this->logger->open($nestedOpen);

        // Close nested operation first
        $nestedClose = new FakeContext('inner finished', 3);
        $this->logger->close($nestedClose, $nestedOpenId);

        // Close outer operation
        $outerClose = new FakeContext('outer finished', 4);
        $this->logger->close($outerClose, $firstOpenId);

        $logJson = $this->logger->flush();

        // Root operation
        $this->assertSame('example_event', $logJson->open[0]->type);
        $this->assertSame('outer process', $logJson->open[0]->context['message']);

        // Close should be the root operation close
        $this->assertSame('example_event', $logJson->close[0]->type);
        $this->assertSame('outer finished', $logJson->close[0]->context['message']);
    }

    public function testJsonSerializableOutput(): void
    {
        $openContext = new FakeContext('test message', 123);
        $openId = $this->logger->open($openContext);

        $closeContext = new FakeContext('test complete', 456);
        $this->logger->close($closeContext, $openId);

        $json = json_encode($this->logger);
        assert(is_string($json));
        $decoded = json_decode($json, true);

        $this->assertSame($this->logger->toArray(), $decoded);
        $this->assertJson($json);
    }

    public function testOpenIdCorrelation(): void
    {
        $openContext = new FakeContext('test message', 123);
        $openId = $this->logger->open($openContext);

        // Add an event - should have openId correlation
        $eventContext = new FakeContext('event occurred', 999);
        $this->logger->event($eventContext);

        $closeContext = new FakeContext('test complete', 456);
        $this->logger->close($closeContext, $openId);

        $logJson = $this->logger->flush();

        // Verify open operation has expected ID
        $this->assertSame($openId, $logJson->open[0]->id);

        // Verify event has openId correlation
        $this->assertCount(1, $logJson->events);
        $eventArray = $logJson->events[0]->toArray();
        $this->assertArrayHasKey('openId', $eventArray);
        if (isset($eventArray['openId'])) {
            $this->assertSame($openId, $eventArray['openId']);
        }

        // Verify close has openId correlation
        $closeArray = $logJson->close[0]->toArray();
        $this->assertArrayHasKey('openId', $closeArray);
        if (isset($closeArray['openId'])) {
            $this->assertSame($openId, $closeArray['openId']);
        }
    }

    public function testNestedOpenIdCorrelation(): void
    {
        $parentContext = new FakeContext('parent operation', 100);
        $parentId = $this->logger->open($parentContext);

        $childContext = new FakeContext('child operation', 200);
        $childId = $this->logger->open($childContext);

        // Event in child context
        $eventContext = new FakeContext('child event', 300);
        $this->logger->event($eventContext);

        $childCloseContext = new FakeContext('child complete', 400);
        $this->logger->close($childCloseContext, $childId);

        $parentCloseContext = new FakeContext('parent complete', 500);
        $this->logger->close($parentCloseContext, $parentId);

        $logJson = $this->logger->flush();

        // Verify parent and child IDs
        $this->assertSame($parentId, $logJson->open[0]->id);
        $this->assertCount(1, $logJson->open[0]->open);
        $this->assertSame($childId, $logJson->open[0]->open[0]->id);

        // Event should be correlated with child operation
        $this->assertCount(1, $logJson->events);
        $eventArray = $logJson->events[0]->toArray();
        $this->assertArrayHasKey('openId', $eventArray);
        if (isset($eventArray['openId'])) {
            $this->assertSame($childId, $eventArray['openId'], 'Event should be correlated with the most recent open operation');
        }

        // Close entries should have correct openId correlation
        $closeArray = $logJson->close[0]->toArray();
        $this->assertArrayHasKey('openId', $closeArray);
        if (isset($closeArray['openId'])) {
            $this->assertSame($parentId, $closeArray['openId']);
        }

        // Nested close should have child openId
        $this->assertArrayHasKey('close', $closeArray);
        if (isset($closeArray['close'])) {
            /** @var list<array<string, mixed>> $nestedCloseList */
            $nestedCloseList = $closeArray['close'];
            $nestedCloseArray = $nestedCloseList[0];
            $this->assertArrayHasKey('openId', $nestedCloseArray);
            if (isset($nestedCloseArray['openId'])) {
                $this->assertSame($childId, $nestedCloseArray['openId']);
            }
        }
    }

    public function testFlushWithoutRelations(): void
    {
        $openContext = new FakeContext('test message', 123);
        $openId = $this->logger->open($openContext);

        $closeContext = new FakeContext('test complete', 456);
        $this->logger->close($closeContext, $openId);

        $logJson = $this->logger->flush();

        // Relations should be empty when not provided
        $this->assertEmpty($logJson->links);

        $logArray = $logJson->toArray();
        $this->assertArrayNotHasKey('links', $logArray);
    }

    public function testJsonOutputDoesNotContainNullFields(): void
    {
        // Test single operation (no nesting)
        $openContext = new FakeContext('simple operation', 1);
        $openId = $this->logger->open($openContext);

        $closeContext = new FakeContext('operation complete', 2);
        $this->logger->close($closeContext, $openId);

        $jsonString = json_encode($this->logger, JSON_PRETTY_PRINT);
        assert(is_string($jsonString));
        $nullFieldCount = substr_count($jsonString, ': null');
        $this->assertSame(0, $nullFieldCount, 'JSON should not contain null fields in simple operations');

        // Test nested operations
        $logger2 = new SemanticLogger();
        $outerOpenId = $logger2->open(new FakeContext('outer operation', 10));
        $innerOpenId = $logger2->open(new FakeContext('inner operation', 20));
        $logger2->close(new FakeContext('inner complete', 30), $innerOpenId);
        $logger2->close(new FakeContext('outer complete', 40), $outerOpenId);

        $nestedJsonString = json_encode($logger2, JSON_PRETTY_PRINT);
        assert(is_string($nestedJsonString));
        $nestedNullFieldCount = substr_count($nestedJsonString, ': null');
        $this->assertSame(0, $nestedNullFieldCount, 'JSON should not contain null fields in nested operations');
    }

    public function testJsonStringOutput(): void
    {
        // Test simple operation structure (schema-based testing)
        $openContext = new FakeContext('test message', 123);
        $openId = $this->logger->open($openContext);

        $closeContext = new FakeContext('test complete', 456);
        $this->logger->close($closeContext, $openId);

        $logJson = $this->logger->flush();

        // Verify structure instead of exact JSON string (more robust)
        $this->assertStringContainsString('schemas/semantic-log.json', $logJson->schemaUrl);

        $serialized = $logJson->toArray();
        $root = $this->serializedOpenEntries($serialized)[0];
        $close = $this->serializedEntryClose($root);

        // Verify open structure
        $this->assertSame('example_event_1', $root['id']);
        $this->assertSame('example_event', $root['type']);
        $this->assertSame('test message', $this->serializedEntryContext($root)['message']);
        $this->assertSame(123, $this->serializedEntryContext($root)['value']);

        // Verify close structure is nested under the root open
        $this->assertSame('example_event_2', $close['id']);
        $this->assertSame('example_event', $close['type']);
        $this->assertSame('test complete', $this->serializedEntryContext($close)['message']);
        $this->assertSame(456, $this->serializedEntryContext($close)['value']);
        $this->assertArrayNotHasKey('openId', $close);
        $this->assertArrayNotHasKey('close', $serialized);

        // Verify JSON serialization quality
        $actualJson = json_encode($logJson, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $this->assertIsString($actualJson);
        $this->assertStringNotContainsString(': null', $actualJson);
        $this->assertStringNotContainsString('"open": null', $actualJson);
        $this->assertStringNotContainsString('"close": null', $actualJson);
    }

    public function testNestedJsonStringOutput(): void
    {
        // Test nested operation structure (schema-based testing)
        $logger = new SemanticLogger();
        $outerOpenId = $logger->open(new FakeContext('outer task', 100));
        $innerOpenId = $logger->open(new FakeContext('inner task', 200));
        $logger->close(new FakeContext('inner done', 300), $innerOpenId);
        $logger->close(new FakeContext('outer done', 400), $outerOpenId);

        $logJson = $logger->flush();

        // Verify nested structure (more robust than exact JSON comparison)
        $this->assertStringContainsString('schemas/semantic-log.json', $logJson->schemaUrl);

        $serialized = $logJson->toArray();
        $outer = $this->serializedOpenEntries($serialized)[0];
        $inner = $this->serializedChildOpens($outer)[0];
        $outerClose = $this->serializedEntryClose($outer);
        $innerClose = $this->serializedEntryClose($inner);

        // Verify outer open
        $this->assertSame('example_event_1', $outer['id']);
        $this->assertSame('outer task', $this->serializedEntryContext($outer)['message']);
        $this->assertSame(100, $this->serializedEntryContext($outer)['value']);

        // Verify inner open (nested)
        $this->assertCount(1, $this->serializedChildOpens($outer));
        $this->assertSame('example_event_2', $inner['id']);
        $this->assertSame('inner task', $this->serializedEntryContext($inner)['message']);
        $this->assertSame(200, $this->serializedEntryContext($inner)['value']);

        // Verify outer close
        $this->assertSame('example_event_4', $outerClose['id']);
        $this->assertSame('outer done', $this->serializedEntryContext($outerClose)['message']);
        $this->assertSame(400, $this->serializedEntryContext($outerClose)['value']);
        $this->assertArrayNotHasKey('openId', $outerClose);

        // Verify inner close (nested under the inner open)
        $this->assertSame('example_event_3', $innerClose['id']);
        $this->assertSame('inner done', $this->serializedEntryContext($innerClose)['message']);
        $this->assertSame(300, $this->serializedEntryContext($innerClose)['value']);
        $this->assertArrayNotHasKey('openId', $innerClose);
        $this->assertArrayNotHasKey('close', $serialized);

        // Verify JSON serialization quality
        $actualJson = json_encode($logJson, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $this->assertIsString($actualJson);
        $this->assertStringNotContainsString(': null', $actualJson);
        $this->assertStringNotContainsString('"open": null', $actualJson);
        $this->assertStringNotContainsString('"close": null', $actualJson);
    }

    public function testFlushWithRelations(): void
    {
        $openContext = new FakeContext('database operation', 123);
        $openId = $this->logger->open($openContext);

        $closeContext = new FakeContext('operation complete', 456);
        $this->logger->close($closeContext, $openId);

        $links = [
            [
                'rel' => 'schema',
                'href' => 'https://example.com/db/schema/users.sql',
                'title' => 'Database Schema',
                'type' => 'application/sql',
            ],
            [
                'rel' => 'source',
                'href' => 'https://github.com/example/app/blob/main/src/UserRepository.php#L42',
                'title' => 'Source Code Location',
                'type' => 'text/x-php',
            ],
            [
                'rel' => 'documentation',
                'href' => 'https://docs.example.com/api/users-query',
                'title' => 'API Documentation',
                'type' => 'text/html',
            ],
        ];

        $logJson = $this->logger->flush($links);

        // Relations should be present
        $this->assertCount(3, $logJson->links);
        $this->assertSame('schema', $logJson->links[0]['rel']);
        $this->assertSame('https://example.com/db/schema/users.sql', $logJson->links[0]['href']);

        $logArray = $logJson->toArray();
        if (! isset($logArray['links'])) {
            self::fail('Serialized log should contain relation links.');
        }

        /** @var list<array{rel: string, href: string, title?: string, type?: string}> $links */
        $links = $logArray['links'];
        $this->assertCount(3, $links);

        // Verify specific relation content
        $link = $links[1];
        $this->assertSame('source', $link['rel']);
        $this->assertArrayHasKey('title', $link);
        $this->assertArrayHasKey('type', $link);
        /** @var array{rel: string, href: string, title: string, type: string} $link */
        $this->assertSame('Source Code Location', $link['title']);
        $this->assertSame('text/x-php', $link['type']);
    }

    public function testFlushWithEmptyRelationsArray(): void
    {
        $openContext = new FakeContext('test message', 123);
        $openId = $this->logger->open($openContext);

        $closeContext = new FakeContext('test complete', 456);
        $this->logger->close($closeContext, $openId);

        $logJson = $this->logger->flush([]);

        // Empty links array should not appear in output
        $this->assertEmpty($logJson->links);

        $logArray = $logJson->toArray();
        $this->assertArrayNotHasKey('links', $logArray);
    }

    public function testRelationsWithComplexStructure(): void
    {
        $openContext = new FakeContext('complex operation', 123);
        $openId = $this->logger->open($openContext);

        $eventContext = new FakeContext('processing step', 456);
        $this->logger->event($eventContext);

        $closeContext = new FakeContext('operation finished', 789);
        $this->logger->close($closeContext, $openId);

        $links = [
            [
                'rel' => 'profile',
                'href' => 'https://xhprof.example.com/run/5f3a2b1c',
                'title' => 'XHProf Performance Profile',
                'type' => 'application/json',
            ],
            [
                'rel' => 'trace',
                'href' => 'https://jaeger.example.com/trace/5f3a2b1c8d9e',
                'title' => 'Distributed Trace',
                'type' => 'application/json',
            ],
        ];

        $logJson = $this->logger->flush($links);

        // Verify links work with events
        $this->assertCount(1, $logJson->events);
        $this->assertCount(2, $logJson->links);

        $logArray = $logJson->toArray();
        $root = $this->serializedOpenEntries($logArray)[0];
        $events = $this->serializedEntryEvents($root);
        $this->assertArrayNotHasKey('events', $logArray);
        if (! isset($logArray['links'])) {
            self::fail('Serialized log should contain relation links.');
        }

        $this->assertCount(1, $events);
        $this->assertSame('processing step', $this->serializedEntryContext($events[0])['message']);

        // Verify trace relation
        /** @var list<array{rel: string, href: string, title?: string, type?: string}> $links */
        $links = $logArray['links'];
        $traceLink = $links[1];
        $this->assertSame('trace', $traceLink['rel']);
        $this->assertSame('https://jaeger.example.com/trace/5f3a2b1c8d9e', $traceLink['href']);
    }

    public function testUnclosedOperationThrowsException(): void
    {
        $openContext = new FakeContext('database operation', 123);
        $openId = $this->logger->open($openContext);

        $eventContext = new FakeContext('processing data', 456);
        $this->logger->event($eventContext);

        // No close() called - should throw UnclosedLogicException
        $this->expectException(UnclosedLogicException::class);
        $this->expectExceptionMessage('Unclosed operations detected. 1 operations remain open. Last operation: example_event.');

        $this->logger->flush();
    }

    public function testUnclosedNestedOperationsThrowsException(): void
    {
        $firstOpen = new FakeContext('outer operation', 1);
        $this->logger->open($firstOpen);

        $secondOpen = new FakeContext('inner operation', 2);
        $this->logger->open($secondOpen);

        $eventContext = new FakeContext('processing', 3);
        $this->logger->event($eventContext);

        // No close() called for nested operations - should throw exception
        $this->expectException(UnclosedLogicException::class);
        $this->expectExceptionMessage('Unclosed operations detected. 2 operations remain open. Last operation: example_event.');

        $this->logger->flush();
    }

    public function testUnclosedOperationExceptionDetails(): void
    {
        $openContext = new FakeContext('database operation', 123);
        $this->logger->open($openContext);

        try {
            $this->logger->flush();
            $this->fail('Expected UnclosedLogicException was not thrown');
        } catch (UnclosedLogicException $e) {
            // Verify exception properties
            $this->assertSame(1, $e->openStackDepth);
            $this->assertSame('example_event', $e->lastOperationType);
            $this->assertSame('https://example.com/schemas/example.json', $e->lastOperationSchema);
            $this->assertStringContainsString('docs/unclosed-operations.md', $e->getMessage());
        }
    }

    public function testCloseWithInvalidOperationId(): void
    {
        $openContext = new FakeContext('test operation', 123);
        $openId = $this->logger->open($openContext);

        $closeContext = new FakeContext('test complete', 456);

        // Try to close with invalid ID
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Cannot close operation 'invalid_id': expected");

        $this->logger->close($closeContext, 'invalid_id');
    }

    public function testCloseAlreadyClosedOperation(): void
    {
        $openContext = new FakeContext('test operation', 123);
        $openId = $this->logger->open($openContext);

        $closeContext = new FakeContext('test complete', 456);
        $this->logger->close($closeContext, $openId);

        // Try to close again
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot close operation');

        $this->logger->close($closeContext, $openId);
    }

    public function testFlushWithNoOperationsThrowsException(): void
    {
        // Coverage: NoLogSessionException when no operations exist + Usage example
        $this->expectException(NoLogSessionException::class);
        $this->expectExceptionMessage('Cannot create log session: no open entry');

        // Try to flush without any operations
        $this->logger->flush();
    }

    public function testDeeplyNestedCloseKeepsEveryLevel(): void
    {
        // Regression test for buildNestedClose dropping intermediate close
        // entries when three or more operations were stacked: the previous
        // implementation reused $result's fields on every loop iteration, so
        // levels between the outermost and innermost close were overwritten.
        $ids = [];
        for ($depth = 1; $depth <= 4; $depth++) {
            $ids[] = $this->logger->open(new FakeContext("open {$depth}", $depth));
        }

        // Close LIFO so each level records its own context message.
        for ($depth = 4; $depth >= 1; $depth--) {
            $this->logger->close(new FakeContext("close {$depth}", $depth), $ids[$depth - 1]);
        }

        $logJson = $this->logger->flush();

        $this->assertCount(1, $logJson->close);
        $close = $logJson->close[0];
        $this->assertSame('close 1', $close->context['message']);

        $this->assertCount(1, $close->close);
        $level2 = $close->close[0];
        $this->assertSame('close 2', $level2->context['message']);

        $this->assertCount(1, $level2->close);
        $level3 = $level2->close[0];
        $this->assertSame('close 3', $level3->context['message']);

        $this->assertCount(1, $level3->close);
        $level4 = $level3->close[0];
        $this->assertSame('close 4', $level4->context['message']);
        $this->assertSame([], $level4->close);
    }

    /**
     * @param array<string, mixed> $serialized
     *
     * @return list<array<string, mixed>>
     */
    private function serializedOpenEntries(array $serialized): array
    {
        $open = $serialized['open'] ?? null;
        if (! is_array($open)) {
            $this->fail('Expected serialized log to contain open entries.');
        }

        $entries = [];
        foreach ($open as $entry) {
            if (! is_array($entry)) {
                $this->fail('Expected serialized open entries to be arrays.');
            }

            $entries[] = $this->normalizeSerializedEntry($entry);
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return list<array<string, mixed>>
     */
    private function serializedChildOpens(array $entry): array
    {
        $open = $entry['open'] ?? null;
        if (! is_array($open)) {
            $this->fail('Expected serialized entry to contain child opens.');
        }

        $children = [];
        foreach ($open as $child) {
            if (! is_array($child)) {
                $this->fail('Expected serialized child opens to be arrays.');
            }

            $children[] = $this->normalizeSerializedEntry($child);
        }

        return $children;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return list<array<string, mixed>>
     */
    private function serializedEntryEvents(array $entry): array
    {
        $events = $entry['events'] ?? null;
        if (! is_array($events)) {
            $this->fail('Expected serialized entry to contain events.');
        }

        $normalizedEvents = [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                $this->fail('Expected serialized events to be arrays.');
            }

            $normalizedEvents[] = $this->normalizeSerializedEntry($event);
        }

        return $normalizedEvents;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function serializedEntryClose(array $entry): array
    {
        $close = $entry['close'] ?? null;
        if (! is_array($close)) {
            $this->fail('Expected serialized entry to contain a close.');
        }

        return $this->normalizeSerializedEntry($close);
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function serializedEntryContext(array $entry): array
    {
        $context = $entry['context'] ?? null;
        if (! is_array($context)) {
            $this->fail('Expected serialized entry to contain a context.');
        }

        return $this->normalizeSerializedEntry($context);
    }

    /**
     * @param array<mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function normalizeSerializedEntry(array $entry): array
    {
        $normalized = [];
        foreach ($entry as $key => $value) {
            if (! is_string($key)) {
                $this->fail('Expected serialized entry keys to be strings.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
