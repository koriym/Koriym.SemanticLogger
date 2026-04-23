<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use Koriym\SemanticLogger\Exception\RuntimeException;
use PHPUnit\Framework\TestCase;

use function json_encode;

final class LogDataParserTest extends TestCase
{
    public function testParseBasicLogData(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'test_1',
                    'type' => 'test_operation',
                    'schemaUrl' => 'test.json',
                    'context' => ['executionTime' => 0.005],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'openId' => 'test_1',
                ],
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
                [
                    'id' => 'parent_1',
                    'type' => 'parent_operation',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'open' => [
                        [
                            'id' => 'child_1',
                            'type' => 'child_operation',
                            'schemaUrl' => 'test.json',
                            'context' => ['responseTime' => 0.010],
                        ],
                    ],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'openId' => 'parent_1',
                ],
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

    public function testParseTreeShapedLogData(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'parent_1',
                    'type' => 'parent_operation',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'events' => [
                        [
                            'id' => 'event_1',
                            'type' => 'infra_event',
                            'schemaUrl' => 'test.json',
                            'context' => ['duration' => 0.002],
                        ],
                    ],
                    'open' => [
                        [
                            'id' => 'child_1',
                            'type' => 'child_operation',
                            'schemaUrl' => 'test.json',
                            'context' => ['responseTime' => 0.010],
                            'close' => [
                                'id' => 'child_close_1',
                                'type' => 'child_close',
                                'schemaUrl' => 'test.json',
                                'context' => ['status' => 'done'],
                            ],
                        ],
                    ],
                    'close' => [
                        'id' => 'parent_close_1',
                        'type' => 'parent_close',
                        'schemaUrl' => 'test.json',
                        'context' => ['status' => 'done'],
                    ],
                ],
            ],
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertSame('parent_1', $tree->id);
        $this->assertSame('parent_close', $tree->closeType);
        $this->assertCount(2, $tree->children);
        $this->assertTrue($tree->children[0]->isEvent);
        $this->assertSame('infra_event', $tree->children[0]->type);
        $this->assertSame('child_1', $tree->children[1]->id);
        $this->assertSame('child_close', $tree->children[1]->closeType);
    }

    public function testParseSiblingChildren(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'parent_1',
                    'type' => 'parent_operation',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'open' => [
                        [
                            'id' => 'sib_1',
                            'type' => 'first',
                            'schemaUrl' => 'test.json',
                            'context' => [],
                        ],
                        [
                            'id' => 'sib_2',
                            'type' => 'second',
                            'schemaUrl' => 'test.json',
                            'context' => [],
                        ],
                    ],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_parent',
                    'type' => 'close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'openId' => 'parent_1',
                ],
            ],
            'events' => [],
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertCount(2, $tree->children);
        $this->assertSame('first', $tree->children[0]->type);
        $this->assertSame('second', $tree->children[1]->type);
    }

    public function testMultipleRootsAreWrappedInSyntheticForestRoot(): void
    {
        $parser = new LogDataParser();
        $tree = $parser->parseLogData([
            'open' => [
                ['id' => 'a_1', 'type' => 'a', 'schemaUrl' => 'a.json', 'context' => []],
                ['id' => 'b_1', 'type' => 'b', 'schemaUrl' => 'b.json', 'context' => []],
            ],
            'close' => [],
            'events' => [],
        ]);

        $this->assertTrue($tree->isSynthetic);
        $this->assertCount(2, $tree->children);
        $this->assertSame('a', $tree->children[0]->type);
        $this->assertSame('b', $tree->children[1]->type);
    }

    public function testParseEventsData(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'operation_1',
                    'type' => 'test_operation',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'openId' => 'operation_1',
                ],
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
                [
                    'id' => 'operation_1',
                    'type' => 'test_operation',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'openId' => 'operation_1',
                ],
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
                    [
                        'id' => 'test_1',
                        'type' => 'test_operation',
                        'schemaUrl' => 'test.json',
                        'context' => $testCase,
                    ],
                ],
                'close' => [
                    [
                        'id' => 'close_1',
                        'type' => 'close',
                        'schemaUrl' => 'test.json',
                        'context' => [],
                        'openId' => 'test_1',
                    ],
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

    public function testEventWithoutOpenId(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'operation_1',
                    'type' => 'test_operation',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'openId' => 'operation_1',
                ],
            ],
            'events' => [
                [
                    'id' => 'orphan_event',
                    'type' => 'orphan',
                    'schemaUrl' => 'test.json',
                    'context' => ['duration' => 0.002],
                    // No openId - should attach to root
                ],
            ],
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertTrue($tree->isSynthetic);
        $this->assertCount(2, $tree->children);
        $this->assertSame('test_operation', $tree->children[0]->type);
        $this->assertSame('orphan', $tree->children[1]->type);
        $this->assertTrue($tree->children[1]->isEvent);
    }

    public function testEventWithInvalidOpenId(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'operation_1',
                    'type' => 'test_operation',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'openId' => 'operation_1',
                ],
            ],
            'events' => [
                [
                    'id' => 'orphan_event',
                    'type' => 'orphan',
                    'schemaUrl' => 'test.json',
                    'context' => ['duration' => 0.002],
                    'openId' => 'nonexistent_id', // ID doesn't exist
                ],
            ],
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertTrue($tree->isSynthetic);
        $this->assertCount(2, $tree->children);
        $this->assertSame('test_operation', $tree->children[0]->type);
        $this->assertSame('orphan', $tree->children[1]->type);
        $this->assertTrue($tree->children[1]->isEvent);
    }

    public function testInvalidEventData(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'operation_1',
                    'type' => 'test_operation',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'openId' => 'operation_1',
                ],
            ],
            'events' => [
                'invalid_event_string', // Non-array event should be skipped
                [
                    'id' => 'valid_event',
                    'type' => 'valid',
                    'schemaUrl' => 'test.json',
                    'context' => ['duration' => 0.002],
                    'openId' => 'operation_1',
                ],
            ],
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertTrue($tree->isSynthetic);
        $this->assertCount(2, $tree->children);
        $this->assertSame('test_operation', $tree->children[0]->type);
        $this->assertSame('unknown', $tree->children[1]->type);
    }

    public function testEventWithNonArrayContext(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'operation_1',
                    'type' => 'test_operation',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'openId' => 'operation_1',
                ],
            ],
            'events' => [
                [
                    'id' => 'event_1',
                    'type' => 'test_event',
                    'schemaUrl' => 'test.json',
                    'context' => 'invalid_context', // Non-array context should be converted to empty array
                    'openId' => 'operation_1',
                ],
            ],
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertCount(1, $tree->children);
        $this->assertSame('test_event', $tree->children[0]->type);
        $this->assertSame([], $tree->children[0]->context);
    }

    public function testOpenEntryWithNonArrayContext(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'operation_1',
                    'type' => 'test_operation',
                    'schemaUrl' => 'test.json',
                    'context' => 'invalid_context', // Non-array context should be converted to empty array
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'openId' => 'operation_1',
                ],
            ],
            'events' => [],
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertSame('test_operation', $tree->type);
        $this->assertSame([], $tree->context);
    }

    public function testOpenEntryWithMissingFields(): void
    {
        $logData = [
            'open' => [['schemaUrl' => 'test.json']], // Missing id, type - should use defaults
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'openId' => 'unknown',
                ],
            ],
            'events' => [],
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertSame('unknown', $tree->id);
        $this->assertSame('unknown', $tree->type);
    }

    public function testEventWithMissingFields(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'operation_1',
                    'type' => 'test_operation',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'openId' => 'operation_1',
                ],
            ],
            'events' => [
                [
                    // Missing id, type - should use defaults
                    'schemaUrl' => 'test.json',
                    'openId' => 'operation_1',
                ],
            ],
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertCount(1, $tree->children);
        $this->assertSame('unknown', $tree->children[0]->id);
        $this->assertSame('unknown', $tree->children[0]->type);
    }

    public function testNonNumericExecutionTimeValues(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'test_1',
                    'type' => 'test_operation',
                    'schemaUrl' => 'test.json',
                    'context' => ['executionTime' => 'not_a_number'], // Non-numeric should result in 0.0
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'openId' => 'test_1',
                ],
            ],
            'events' => [],
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertSame(0.0, $tree->executionTime);
    }

    public function testLogDataWithoutEventsSection(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'operation_1',
                    'type' => 'test_operation',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'openId' => 'operation_1',
                ],
            ],
            // No events section
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertSame('test_operation', $tree->type);
        $this->assertEmpty($tree->children); // No events should be attached
    }

    public function testLogDataWithInvalidEventsSection(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'operation_1',
                    'type' => 'test_operation',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'openId' => 'operation_1',
                ],
            ],
            'events' => 'not_an_array', // Invalid events section
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertSame('test_operation', $tree->type);
        $this->assertEmpty($tree->children); // No events should be attached
    }

    public function testCloseContextIsAttachedToMatchingOpen(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'operation_1',
                    'type' => 'process_open',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'process_close',
                    'schemaUrl' => 'test.json',
                    'context' => ['result' => 'ok'],
                    'openId' => 'operation_1',
                ],
            ],
            'events' => [],
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertSame('process_close', $tree->closeType);
        $this->assertSame(['result' => 'ok'], $tree->closeContext);
    }

    public function testCloseProfileIsAttachedToMatchingOpen(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'operation_1',
                    'type' => 'process_open',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'process_close',
                    'schemaUrl' => 'test.json',
                    'context' => ['result' => 'ok'],
                    'profile' => [
                        'wallTime' => 0.123,
                        'xdebugTrace' => [
                            ['path' => '/tmp/profile.xt'],
                        ],
                    ],
                    'openId' => 'operation_1',
                ],
            ],
            'events' => [],
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertSame([
            'wallTime' => 0.123,
            'xdebugTrace' => [
                ['path' => '/tmp/profile.xt'],
            ],
        ], $tree->closeProfile);
    }

    public function testNestedCloseIsMatchedToNestedOpen(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'outer_1',
                    'type' => 'outer_open',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'open' => [
                        [
                            'id' => 'inner_1',
                            'type' => 'inner_open',
                            'schemaUrl' => 'test.json',
                            'context' => [],
                        ],
                    ],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_outer',
                    'type' => 'outer_close',
                    'schemaUrl' => 'test.json',
                    'context' => ['outer' => true],
                    'openId' => 'outer_1',
                    'close' => [
                        [
                            'id' => 'close_inner',
                            'type' => 'inner_close',
                            'schemaUrl' => 'test.json',
                            'context' => ['inner' => true],
                            'openId' => 'inner_1',
                        ],
                    ],
                ],
            ],
            'events' => [],
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertSame('outer_close', $tree->closeType);
        $this->assertSame(['outer' => true], $tree->closeContext);

        $this->assertCount(1, $tree->children);
        $inner = $tree->children[0];
        $this->assertSame('inner_close', $inner->closeType);
        $this->assertSame(['inner' => true], $inner->closeContext);
    }

    public function testCloseWithUnknownOpenIdIsAttachedAsDiagnosticNode(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'operation_1',
                    'type' => 'process_open',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'process_close',
                    'schemaUrl' => 'test.json',
                    'context' => ['result' => 'ok'],
                    'openId' => 'does_not_exist',
                ],
            ],
            'events' => [],
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertNull($tree->closeType);
        $this->assertSame([], $tree->closeContext);
        $this->assertTrue($tree->isSynthetic);
        $this->assertCount(2, $tree->children);
        $this->assertSame('process_open', $tree->children[0]->type);
        $this->assertSame('close_1', $tree->children[1]->id);
        $this->assertSame('process_close', $tree->children[1]->type);
        $this->assertTrue($tree->children[1]->isOrphanClose);
        $this->assertSame(['result' => 'ok'], $tree->children[1]->context);
    }

    public function testCloseWithoutOpenIdIsAttachedAsDiagnosticNode(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'operation_1',
                    'type' => 'process_open',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
                [
                    'id' => 'operation_2',
                    'type' => 'other_open',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'process_close',
                    'schemaUrl' => 'test.json',
                    'context' => ['result' => 'ok'],
                ],
            ],
            'events' => [],
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertTrue($tree->isSynthetic);
        $this->assertCount(3, $tree->children);
        $this->assertSame('close_1', $tree->children[2]->id);
        $this->assertSame('process_close', $tree->children[2]->type);
        $this->assertTrue($tree->children[2]->isOrphanClose);
    }

    public function testCloseWithoutOpenIdRemainsDiagnosticNodeInSingleRootLog(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'operation_1',
                    'type' => 'process_open',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'process_close',
                    'schemaUrl' => 'test.json',
                    'context' => ['result' => 'ok'],
                ],
            ],
            'events' => [],
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertTrue($tree->isSynthetic);
        $this->assertCount(2, $tree->children);
        $this->assertSame('process_open', $tree->children[0]->type);
        $this->assertNull($tree->children[0]->closeType);
        $this->assertSame('process_close', $tree->children[1]->type);
        $this->assertTrue($tree->children[1]->isOrphanClose);
        $this->assertSame(['result' => 'ok'], $tree->children[1]->context);
    }

    public function testMissingOrphanCloseIdsReceiveUniqueFallbackIds(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'operation_1',
                    'type' => 'process_open',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
            ],
            'close' => [
                [
                    'type' => 'process_close',
                    'schemaUrl' => 'test.json',
                    'context' => ['result' => 'first'],
                ],
                [
                    'type' => 'process_close',
                    'schemaUrl' => 'test.json',
                    'context' => ['result' => 'second'],
                ],
            ],
            'events' => [],
        ];

        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        $this->assertTrue($tree->isSynthetic);
        $this->assertCount(3, $tree->children);
        $this->assertSame('process_open', $tree->children[0]->type);
        $this->assertSame('orphan_close_1', $tree->children[1]->id);
        $this->assertSame('orphan_close_2', $tree->children[2]->id);
        $this->assertTrue($tree->children[1]->isOrphanClose);
        $this->assertTrue($tree->children[2]->isOrphanClose);
    }
}
