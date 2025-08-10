<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use PHPUnit\Framework\TestCase;
use RuntimeException;

use function json_encode;

final class LogDataParserTest extends TestCase
{
    public function testParseBasicLogData(): void
    {
        $logData = [
            'open' => [
                'id' => 'test_1',
                'type' => 'test_operation',
                'schemaUrl' => 'test.json',
                'context' => ['executionTime' => 0.005],
            ],
            'close' => [
                'id' => 'close_1',
                'type' => 'close',
                'schemaUrl' => 'test.json',
                'context' => [],
            ],
            'events' => [],
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertSame('test_1', $tree->id);
        $this->assertSame('test_operation', $tree->type);
        $this->assertSame(0.005, $tree->executionTime);
        $this->assertEmpty($tree->children);
    }

    public function testParseNestedLogData(): void
    {
        $logData = [
            'open' => [
                'id' => 'parent_1',
                'type' => 'parent_operation',
                'schemaUrl' => 'test.json',
                'context' => [],
                'open' => [
                    'id' => 'child_1',
                    'type' => 'child_operation',
                    'schemaUrl' => 'test.json',
                    'context' => ['responseTime' => 0.010],
                ],
            ],
            'close' => [
                'id' => 'close_1',
                'type' => 'close',
                'schemaUrl' => 'test.json',
                'context' => [],
            ],
            'events' => [],
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertSame('parent_1', $tree->id);
        $this->assertSame('parent_operation', $tree->type);
        $this->assertCount(1, $tree->children);

        $child = $tree->children[0];
        $this->assertSame('child_1', $child->id);
        $this->assertSame('child_operation', $child->type);
        $this->assertSame(0.010, $child->executionTime);
    }

    public function testParseEventsData(): void
    {
        $logData = [
            'open' => [
                'id' => 'operation_1',
                'type' => 'test_operation',
                'schemaUrl' => 'test.json',
                'context' => [],
            ],
            'close' => [
                'id' => 'close_1',
                'type' => 'close',
                'schemaUrl' => 'test.json',
                'context' => [],
            ],
            'events' => [
                [
                    'id' => 'event_1',
                    'type' => 'test_event',
                    'schemaUrl' => 'test.json',
                    'context' => ['duration' => 0.003],
                    'openId' => 'operation_1',
                ],
            ],
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertSame('operation_1', $tree->id);
        $this->assertCount(1, $tree->children);

        $event = $tree->children[0];
        $this->assertSame('event_1', $event->id);
        $this->assertSame('test_event', $event->type);
        $this->assertSame(0.003, $event->executionTime);
    }

    public function testParseMultipleEvents(): void
    {
        $logData = [
            'open' => [
                'id' => 'operation_1',
                'type' => 'test_operation',
                'schemaUrl' => 'test.json',
                'context' => [],
            ],
            'close' => [
                'id' => 'close_1',
                'type' => 'close',
                'schemaUrl' => 'test.json',
                'context' => [],
            ],
            'events' => [
                [
                    'id' => 'event_1',
                    'type' => 'first_event',
                    'schemaUrl' => 'test.json',
                    'context' => ['duration' => 0.002],
                    'openId' => 'operation_1',
                ],
                [
                    'id' => 'event_2',
                    'type' => 'second_event',
                    'schemaUrl' => 'test.json',
                    'context' => ['duration' => 0.004],
                    'openId' => 'operation_1',
                ],
            ],
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertCount(2, $tree->children);

        $firstEvent = $tree->children[0];
        $this->assertSame('first_event', $firstEvent->type);
        $this->assertSame(0.002, $firstEvent->executionTime);

        $secondEvent = $tree->children[1];
        $this->assertSame('second_event', $secondEvent->type);
        $this->assertSame(0.004, $secondEvent->executionTime);
    }

    public function testExtractExecutionTimeFromDifferentFields(): void
    {
        $testCases = [
            ['executionTime' => 0.005, 'expected' => 0.005],
            ['responseTime' => 0.010, 'expected' => 0.010],
            ['duration' => 0.015, 'expected' => 0.015],
            ['processingTime' => 0.020, 'expected' => 0.020],
            ['connectionTime' => 0.025, 'expected' => 0.025],
            ['other' => 0.030, 'expected' => 0.0], // Should default to 0.0
        ];

        foreach ($testCases as $testCase) {
            $expected = $testCase['expected'];
            unset($testCase['expected']);

            $logData = [
                'open' => [
                    'id' => 'test_1',
                    'type' => 'test_operation',
                    'schemaUrl' => 'test.json',
                    'context' => $testCase,
                ],
                'close' => [
                    'id' => 'close_1',
                    'type' => 'close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
                'events' => [],
            ];

            $parser = new LogDataParser();
            $tree = $parser->parseLogData($logData);

            $this->assertSame(
                $expected,
                $tree->executionTime,
                'Failed for context: ' . json_encode($testCase),
            );
        }
    }

    public function testInvalidLogDataMissingOpen(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid log data: missing open section');

        $logData = [
            'close' => [],
            'events' => [],
        ];

        $parser = new LogDataParser();
        $parser->parseLogData($logData);
    }

    public function testInvalidLogDataInvalidOpen(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid log data: missing open section');

        $logData = [
            'open' => 'invalid',
            'close' => [],
            'events' => [],
        ];

        $parser = new LogDataParser();
        $parser->parseLogData($logData);
    }
}
