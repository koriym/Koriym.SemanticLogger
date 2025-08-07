<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use Koriym\SemanticLogger\Exception\NoLogSessionException;
use Koriym\SemanticLogger\Exception\UnclosedLogicException;
use LogicException;
use PHPUnit\Framework\TestCase;

use function assert;
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

        $this->assertSame('https://koriym.github.io/Koriym.SemanticLogger/schemas/combined.json', $logJson->schemaUrl);

        // Open - check ID
        $this->assertSame('process_start_1', $logJson->open->id);
        $this->assertSame('process_start', $logJson->open->type);
        $this->assertSame('https://example.com/schemas/process_start.json', $logJson->open->schemaUrl);
        $this->assertSame('starting process', $logJson->open->context['message']);
        $this->assertSame(1, $logJson->open->context['id']);

        // Events - check ID
        $this->assertCount(1, $logJson->events);
        $this->assertSame('example_event_1', $logJson->events[0]->id);
        $this->assertSame('example_event', $logJson->events[0]->type);
        $this->assertSame('https://example.com/schemas/example.json', $logJson->events[0]->schemaUrl);
        $this->assertSame('processing data', $logJson->events[0]->context['message']);
        $this->assertSame(42, $logJson->events[0]->context['value']);

        // Close - check ID
        $this->assertSame('process_complete_1', $logJson->close->id);
        $this->assertSame('process_complete', $logJson->close->type);
        $this->assertSame('https://example.com/schemas/process_complete.json', $logJson->close->schemaUrl);
        $this->assertSame('completed successfully', $logJson->close->context['result']);
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
        $this->assertSame('example_event', $logJson->open->type);
        $this->assertSame('outer process', $logJson->open->context['message']);

        // Close should be the root operation close
        $this->assertSame('example_event', $logJson->close->type);
        $this->assertSame('outer finished', $logJson->close->context['message']);
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
        $this->assertSame($openId, $logJson->open->id);

        // Verify event has openId correlation
        $this->assertCount(1, $logJson->events);
        $eventArray = $logJson->events[0]->toArray();
        $this->assertArrayHasKey('openId', $eventArray);
        if (isset($eventArray['openId'])) {
            $this->assertSame($openId, $eventArray['openId']);
        }

        // Verify close has openId correlation
        $closeArray = $logJson->close->toArray();
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
        $this->assertSame($parentId, $logJson->open->id);
        $this->assertNotNull($logJson->open->open);
        $this->assertSame($childId, $logJson->open->open->id);

        // Event should be correlated with child operation
        $this->assertCount(1, $logJson->events);
        $eventArray = $logJson->events[0]->toArray();
        $this->assertArrayHasKey('openId', $eventArray);
        if (isset($eventArray['openId'])) {
            $this->assertSame($childId, $eventArray['openId'], 'Event should be correlated with the most recent open operation');
        }

        // Close entries should have correct openId correlation
        $closeArray = $logJson->close->toArray();
        $this->assertArrayHasKey('openId', $closeArray);
        if (isset($closeArray['openId'])) {
            $this->assertSame($parentId, $closeArray['openId']);
        }

        // Nested close should have child openId
        $this->assertArrayHasKey('close', $closeArray);
        if (isset($closeArray['close'])) {
            /** @var array<string, mixed> $nestedCloseArray */
            $nestedCloseArray = $closeArray['close'];
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
        $this->assertStringContainsString('schemas/combined.json', $logJson->schemaUrl);

        // Verify open structure
        $this->assertSame('example_event_1', $logJson->open->id);
        $this->assertSame('example_event', $logJson->open->type);
        $this->assertSame('test message', $logJson->open->context['message']);
        $this->assertSame(123, $logJson->open->context['value']);

        // Verify close structure
        $this->assertSame('example_event_2', $logJson->close->id);
        $this->assertSame('example_event', $logJson->close->type);
        $this->assertSame('test complete', $logJson->close->context['message']);
        $this->assertSame(456, $logJson->close->context['value']);
        $this->assertSame('example_event_1', $logJson->close->openId);

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
        $this->assertStringContainsString('schemas/combined.json', $logJson->schemaUrl);

        // Verify outer open
        $this->assertSame('example_event_1', $logJson->open->id);
        $this->assertSame('outer task', $logJson->open->context['message']);
        $this->assertSame(100, $logJson->open->context['value']);

        // Verify inner open (nested)
        $this->assertNotNull($logJson->open->open);
        $this->assertSame('example_event_2', $logJson->open->open->id);
        $this->assertSame('inner task', $logJson->open->open->context['message']);
        $this->assertSame(200, $logJson->open->open->context['value']);

        // Verify outer close
        $this->assertSame('example_event_4', $logJson->close->id);
        $this->assertSame('outer done', $logJson->close->context['message']);
        $this->assertSame(400, $logJson->close->context['value']);
        $this->assertSame('example_event_1', $logJson->close->openId);

        // Verify inner close (nested)
        $this->assertNotNull($logJson->close->close);
        $this->assertSame('example_event_3', $logJson->close->close->id);
        $this->assertSame('inner done', $logJson->close->close->context['message']);
        $this->assertSame(300, $logJson->close->close->context['value']);
        $this->assertSame('example_event_2', $logJson->close->close->openId);

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
        $this->assertArrayHasKey('links', $logArray);
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
        $this->assertArrayHasKey('events', $logArray);
        $this->assertArrayHasKey('links', $logArray);

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

        // Try to flush without any operations
        $this->logger->flush();
    }
}
