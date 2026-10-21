<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;
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
        $this->logger->event(new FakeContext('processing data', 42));

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

        $array = $logJson->toArray();
        $this->assertSame([
            [
                'id' => 'process_start_1',
                'type' => 'process_start',
                'schemaUrl' => 'https://example.com/schemas/process_start.json',
                'context' => ['message' => 'starting process', 'id' => 1],
                'log' => [
                    [
                        'id' => 'example_event_1',
                        'type' => 'example_event',
                        'schemaUrl' => 'https://example.com/schemas/example.json',
                        'context' => ['message' => 'processing data', 'value' => 42],
                    ],
                ],
                'close' => [
                    'id' => 'process_complete_1',
                    'type' => 'process_complete',
                    'schemaUrl' => 'https://example.com/schemas/process_complete.json',
                    'context' => ['result' => 'completed successfully'],
                ],
            ],
        ], $array['log']);
    }

    public function testSequentialSiblingsAreRecordedAsSiblings(): void
    {
        // Regression for #24: sequential opens must not be reconstructed as nested.
        $first = $this->logger->open(new FakeContext('first', 1));
        $this->logger->close(new FakeContext('first done', 2), $first);

        $second = $this->logger->open(new FakeContext('second', 3));
        $this->logger->close(new FakeContext('second done', 4), $second);

        $log = $this->logger->flush()->toArray()['log'];

        $this->assertCount(2, $log, 'top-level opens should be siblings, not nested');
        $this->assertSame($first, $log[0]['id']);
        $this->assertSame('first', $this->messageOf($log[0]));
        $this->assertArrayNotHasKey('log', $log[0], 'closed open with no children omits log');
        $this->assertSame('first done', $this->messageOf($this->closeOf($log[0])));

        $this->assertSame($second, $log[1]['id']);
        $this->assertSame('second', $this->messageOf($log[1]));
        $this->assertArrayNotHasKey('log', $log[1]);
        $this->assertSame('second done', $this->messageOf($this->closeOf($log[1])));
    }

    public function testOuterWithTwoSiblingInnersKeepsRealOrder(): void
    {
        $outer = $this->logger->open(new FakeContext('outer', 1));

        $inner1 = $this->logger->open(new FakeContext('inner1', 2));
        $this->logger->close(new FakeContext('inner1 done', 3), $inner1);

        $inner2 = $this->logger->open(new FakeContext('inner2', 4));
        $this->logger->close(new FakeContext('inner2 done', 5), $inner2);

        $this->logger->close(new FakeContext('outer done', 6), $outer);

        $log = $this->logger->flush()->toArray()['log'];

        $this->assertCount(1, $log);
        $this->assertSame($outer, $log[0]['id']);
        $this->assertSame([$inner1, $inner2], array_column($this->childrenOf($log[0]), 'id'));
    }

    public function testRecordOrderIsPreservedInChildren(): void
    {
        $a = $this->logger->open(new FakeContext('A', 1));
        $this->logger->event(new FakeContext('e1', 2));
        $b = $this->logger->open(new FakeContext('B', 3));
        $this->logger->close(new FakeContext('B done', 4), $b);
        $this->logger->event(new FakeContext('e2', 5));
        $this->logger->close(new FakeContext('A done', 6), $a);

        $log = $this->logger->flush()->toArray()['log'];

        $children = $this->childrenOf($log[0]);
        $this->assertSame(['event', 'open', 'event'], array_map($this->kindOf(...), $children));
        $this->assertSame('e1', $this->messageOf($children[0]));
        $this->assertSame($b, $children[1]['id']);
        $this->assertSame('B done', $this->messageOf($this->closeOf($children[1])));
        $this->assertSame('e2', $this->messageOf($children[2]));
    }

    public function testFlushRecordsKeepClosesInterleavedAtRecordPosition(): void
    {
        // Regression: closes sit in the record list at the position they were
        // recorded, not collected into a separate list.
        $a = $this->logger->open(new FakeContext('A', 1));
        $this->logger->event(new FakeContext('e1', 2));
        $this->logger->close(new FakeContext('A done', 3), $a);
        $this->logger->event(new FakeContext('e2', 4));

        $records = $this->logger->flush()->records;

        $this->assertCount(4, $records);
        $this->assertInstanceOf(OpenEntry::class, $records[0]);
        $this->assertInstanceOf(EventEntry::class, $records[1]);
        $this->assertInstanceOf(CloseEntry::class, $records[2]);
        $this->assertInstanceOf(EventEntry::class, $records[3]);
        $this->assertSame($a, $records[2]->openId);
    }

    public function testNestedOpen(): void
    {
        $outerId = $this->logger->open(new FakeContext('outer process', 1));
        $innerId = $this->logger->open(new FakeContext('inner process', 2));
        $this->logger->close(new FakeContext('inner finished', 3), $innerId);
        $this->logger->close(new FakeContext('outer finished', 4), $outerId);

        $array = $this->logger->flush()->toArray();
        $outer = $array['log'][0];

        $this->assertSame('example_event', $outer['type']);
        $this->assertSame('outer process', $this->messageOf($outer));
        $this->assertSame('outer finished', $this->messageOf($this->closeOf($outer)));

        $inner = $this->childrenOf($outer)[0];
        $this->assertSame('inner process', $this->messageOf($inner));
        $this->assertSame('inner finished', $this->messageOf($this->closeOf($inner)));
    }

    public function testJsonSerializableOutput(): void
    {
        $openId = $this->logger->open(new FakeContext('test message', 123));
        $this->logger->close(new FakeContext('test complete', 456), $openId);

        $json = json_encode($this->logger);
        assert(is_string($json));
        $decoded = json_decode($json, true);

        $this->assertSame($this->logger->toArray(), $decoded);
        $this->assertJson($json);
    }

    public function testToArrayOnLiveLoggerDoesNotConsumeState(): void
    {
        $openId = $this->logger->open(new FakeContext('live', 1));
        $this->logger->event(new FakeContext('inside', 2));

        $preview = $this->logger->toArray();
        $this->assertSame($preview, $this->logger->toArray(), 'repeated previews must be identical');
        $this->assertSame($openId, $preview['log'][0]['id']);

        $this->logger->close(new FakeContext('done', 3), $openId);
        $flushed = $this->logger->flush()->toArray();

        $open = $flushed['log'][0];
        $this->assertCount(1, $flushed['log']);
        $this->assertSame($openId, $open['id']);
        $this->assertSame(['inside'], array_column(array_column($this->childrenOf($open), 'context'), 'message'));
        $this->assertSame('done', $this->messageOf($this->closeOf($open)));
    }

    public function testPreviewOfUnclosedLogMatchesFlush(): void
    {
        $this->logger->open(new FakeContext('never closed', 1));

        $preview = $this->logger->toArray();
        $flushed = $this->logger->flush()->toArray();

        $this->assertSame($preview, $flushed);
        $this->assertSame(
            'unclosed_at_flush',
            $this->contextValue($this->unclosedDiagnostic($flushed['log']), 'code'),
        );
    }

    public function testEventAndCloseAreScopedByStructureNotByOpenId(): void
    {
        $parentId = $this->logger->open(new FakeContext('parent operation', 100));
        $childId = $this->logger->open(new FakeContext('child operation', 200));
        $this->logger->event(new FakeContext('child event', 300));
        $this->logger->close(new FakeContext('child complete', 400), $childId);
        $this->logger->close(new FakeContext('parent complete', 500), $parentId);

        $array = $this->logger->flush()->toArray();
        $parent = $array['log'][0];
        $child = $this->childrenOf($parent)[0];
        $event = $this->childrenOf($child)[0];

        $this->assertSame($parentId, $parent['id']);
        $this->assertSame($childId, $child['id']);
        $this->assertArrayNotHasKey('log', $event);
        $this->assertArrayNotHasKey('close', $event);
        $this->assertSame('child event', $this->messageOf($event));
        $this->assertArrayNotHasKey('openId', $event);
        $this->assertArrayNotHasKey('openId', $this->closeOf($parent));
        $this->assertArrayNotHasKey('openId', $this->closeOf($child));
    }

    public function testFlushWithoutRelations(): void
    {
        $openId = $this->logger->open(new FakeContext('test message', 123));
        $this->logger->close(new FakeContext('test complete', 456), $openId);

        $logJson = $this->logger->flush();

        $this->assertEmpty($logJson->links);
        $this->assertArrayNotHasKey('links', $logJson->toArray());
    }

    public function testJsonOutputDoesNotContainNullFields(): void
    {
        $openId = $this->logger->open(new FakeContext('simple operation', 1));
        $this->logger->close(new FakeContext('operation complete', 2), $openId);

        $jsonString = json_encode($this->logger, JSON_PRETTY_PRINT);
        assert(is_string($jsonString));
        $this->assertSame(0, substr_count($jsonString, ': null'), 'JSON should not contain null fields in simple operations');

        $logger2 = new SemanticLogger();
        $outerOpenId = $logger2->open(new FakeContext('outer operation', 10));
        $logger2->event(new FakeContext('inner event', 15));
        $innerOpenId = $logger2->open(new FakeContext('inner operation', 20));
        $logger2->close(new FakeContext('inner complete', 30), $innerOpenId);
        $logger2->close(new FakeContext('outer complete', 40), $outerOpenId);

        $nestedJsonString = json_encode($logger2, JSON_PRETTY_PRINT);
        assert(is_string($nestedJsonString));
        $this->assertSame(0, substr_count($nestedJsonString, ': null'), 'JSON should not contain null fields in nested operations');
    }

    public function testJsonStringOutput(): void
    {
        $openId = $this->logger->open(new FakeContext('test message', 123));
        $this->logger->close(new FakeContext('test complete', 456), $openId);

        $logJson = $this->logger->flush();

        $this->assertStringContainsString('schemas/semantic-log.json', $logJson->schemaUrl);

        $array = $logJson->toArray();
        $root = $array['log'][0];
        $close = $this->closeOf($root);

        $this->assertSame('example_event_1', $root['id']);
        $this->assertSame('example_event', $root['type']);
        $this->assertSame(['message' => 'test message', 'value' => 123], $root['context']);

        $this->assertSame('example_event_2', $close['id']);
        $this->assertSame('example_event', $close['type']);
        $this->assertSame(['message' => 'test complete', 'value' => 456], $close['context']);
        $this->assertArrayNotHasKey('openId', $close);

        $actualJson = json_encode($logJson, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $this->assertIsString($actualJson);
        $this->assertStringNotContainsString(': null', $actualJson);
    }

    public function testNestedJsonStringOutput(): void
    {
        $outerOpenId = $this->logger->open(new FakeContext('outer task', 100));
        $innerOpenId = $this->logger->open(new FakeContext('inner task', 200));
        $this->logger->close(new FakeContext('inner done', 300), $innerOpenId);
        $this->logger->close(new FakeContext('outer done', 400), $outerOpenId);

        $logJson = $this->logger->flush();

        $this->assertStringContainsString('schemas/semantic-log.json', $logJson->schemaUrl);

        $array = $logJson->toArray();
        $outer = $array['log'][0];
        $children = $this->childrenOf($outer);
        $inner = $children[0];

        $this->assertSame('example_event_1', $outer['id']);
        $this->assertSame(['message' => 'outer task', 'value' => 100], $outer['context']);

        $this->assertCount(1, $children);
        $this->assertSame('example_event_2', $inner['id']);
        $this->assertSame(['message' => 'inner task', 'value' => 200], $inner['context']);

        $outerClose = $this->closeOf($outer);
        $this->assertSame('example_event_4', $outerClose['id']);
        $this->assertSame(['message' => 'outer done', 'value' => 400], $outerClose['context']);
        $this->assertArrayNotHasKey('openId', $outerClose);

        $innerClose = $this->closeOf($inner);
        $this->assertSame('example_event_3', $innerClose['id']);
        $this->assertSame(['message' => 'inner done', 'value' => 300], $innerClose['context']);
        $this->assertArrayNotHasKey('openId', $innerClose);

        $actualJson = json_encode($logJson, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $this->assertIsString($actualJson);
        $this->assertStringNotContainsString(': null', $actualJson);
    }

    public function testFlushWithRelations(): void
    {
        $openId = $this->logger->open(new FakeContext('database operation', 123));
        $this->logger->close(new FakeContext('operation complete', 456), $openId);

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

        $this->assertCount(3, $logJson->links);
        $this->assertSame('schema', $logJson->links[0]['rel']);
        $this->assertSame('https://example.com/db/schema/users.sql', $logJson->links[0]['href']);

        $logArray = $logJson->toArray();
        if (! isset($logArray['links'])) {
            self::fail('Serialized log should contain relation links.');
        }

        $this->assertSame($links, $logArray['links']);
    }

    public function testFlushWithEmptyRelationsArray(): void
    {
        $openId = $this->logger->open(new FakeContext('test message', 123));
        $this->logger->close(new FakeContext('test complete', 456), $openId);

        $logJson = $this->logger->flush([]);

        $this->assertEmpty($logJson->links);
        $this->assertArrayNotHasKey('links', $logJson->toArray());
    }

    public function testRelationsWithComplexStructure(): void
    {
        $openId = $this->logger->open(new FakeContext('complex operation', 123));
        $this->logger->event(new FakeContext('processing step', 456));
        $this->logger->close(new FakeContext('operation finished', 789), $openId);

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

        $this->assertCount(2, $logJson->links);

        $logArray = $logJson->toArray();
        $children = $this->childrenOf($logArray['log'][0]);
        $this->assertCount(1, $children);
        $this->assertSame('processing step', $this->messageOf($children[0]));
        $this->assertSame($links, $logArray['links'] ?? null);
    }

    public function testDeeplyNestedCloseKeepsEveryLevel(): void
    {
        // Regression: intermediate closes were dropped when 3+ operations were stacked.
        $ids = [];
        for ($depth = 1; $depth <= 4; $depth++) {
            $ids[] = $this->logger->open(new FakeContext("open {$depth}", $depth));
        }

        for ($depth = 4; $depth >= 1; $depth--) {
            $this->logger->close(new FakeContext("close {$depth}", $depth), $ids[$depth - 1]);
        }

        $array = $this->logger->flush()->toArray();
        $this->assertCount(1, $array['log']);

        $entry = $array['log'][0];
        for ($depth = 1; $depth <= 4; $depth++) {
            $this->assertSame("open {$depth}", $this->messageOf($entry));
            $this->assertSame("close {$depth}", $this->messageOf($this->closeOf($entry)));
            $children = $this->childrenOf($entry);
            if ($depth === 4) {
                $this->assertSame([], $children);
                $this->assertArrayNotHasKey('log', $entry, 'closed leaf with no children omits log');
                break;
            }

            $this->assertCount(1, $children);
            $entry = $children[0];
        }
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return list<array<string, mixed>>
     */
    private function childrenOf(array $entry): array
    {
        return $this->entryList($entry['log'] ?? []);
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function closeOf(array $entry): array
    {
        $close = $entry['close'] ?? null;
        $this->assertIsArray($close);

        return $this->stringKeyed($close);
    }

    /**
     * Derived structurally: an interval carries `close` or `log`, a point carries neither.
     *
     * @param array<string, mixed> $entry
     */
    private function kindOf(array $entry): string
    {
        return isset($entry['close']) || isset($entry['log']) ? 'open' : 'event';
    }

    /** @param array<string, mixed> $entry */
    private function messageOf(array $entry): mixed
    {
        return $this->contextValue($entry, 'message');
    }

    /** @param array<string, mixed> $entry */
    private function contextValue(array $entry, string $key): mixed
    {
        $context = $entry['context'] ?? null;
        $this->assertIsArray($context);

        return $context[$key] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return array<string, mixed>
     */
    private function unclosedDiagnostic(array $entries): array
    {
        foreach ($entries as $entry) {
            if (($entry['type'] ?? null) === CoreSchema::DIAGNOSTIC_TYPE) {
                return $entry;
            }
        }

        $this->fail('Expected a diagnostic entry.');
    }

    /** @return list<array<string, mixed>> */
    private function entryList(mixed $value): array
    {
        $this->assertIsArray($value);

        $entries = [];
        foreach ($value as $entry) {
            $this->assertIsArray($entry);
            $entries[] = $this->stringKeyed($entry);
        }

        return $entries;
    }

    /**
     * @param array<mixed> $array
     *
     * @return array<string, mixed>
     */
    private function stringKeyed(array $array): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $this->assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }
}
