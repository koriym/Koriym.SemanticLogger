<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use Koriym\SemanticLogger\Stree\Exception\RuntimeException;
use PHPUnit\Framework\TestCase;

use function json_encode;

final class LogDataParserTest extends TestCase
{
    /**
     * @param array<string, mixed>       $context
     * @param list<array<string, mixed>> $children
     * @param array<string, mixed>|null  $close
     *
     * @return array<string, mixed>
     */
    private static function open(string $id, string $type, array $context = [], array $children = [], array|null $close = null): array
    {
        $entry = [
            'kind' => 'open',
            'id' => $id,
            'type' => $type,
            'schemaUrl' => 'test.json',
            'context' => $context,
            'children' => $children,
        ];

        if ($close !== null) {
            $entry['close'] = $close;
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private static function event(string $id, string $type, array $context = []): array
    {
        return [
            'kind' => 'event',
            'id' => $id,
            'type' => $type,
            'schemaUrl' => 'test.json',
            'context' => $context,
        ];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private static function close(string $id, string $type = 'close', array $context = []): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'schemaUrl' => 'test.json',
            'context' => $context,
        ];
    }

    public function testParseBasicLogData(): void
    {
        $tree = (new LogDataParser())->parseLogData([
            'entries' => [
                self::open('test_1', 'test_operation', ['executionTime' => 0.005], [], self::close('close_1')),
            ],
        ]);

        $this->assertFalse($tree->isSynthetic);
        $this->assertSame('test_1', $tree->id);
        $this->assertSame('test_operation', $tree->type);
        $this->assertSame(0.005, $tree->executionTime);
        $this->assertEmpty($tree->children);
    }

    public function testParseNestedLogData(): void
    {
        $tree = (new LogDataParser())->parseLogData([
            'entries' => [
                self::open('parent_1', 'parent_operation', [], [
                    self::open('child_1', 'child_operation', ['responseTime' => 0.010]),
                ], self::close('close_1')),
            ],
        ]);

        $this->assertSame('parent_1', $tree->id);
        $this->assertSame('parent_operation', $tree->type);
        $this->assertCount(1, $tree->children);

        $child = $tree->children[0];
        $this->assertSame('child_1', $child->id);
        $this->assertSame('child_operation', $child->type);
        $this->assertSame(0.010, $child->executionTime);
        $this->assertSame($tree, $child->parent);
    }

    public function testChildrenKeepRecordedOrder(): void
    {
        $tree = (new LogDataParser())->parseLogData([
            'entries' => [
                self::open('parent_1', 'parent_operation', [], [
                    self::open('sib_1', 'first', [], [], self::close('c1')),
                    self::event('event_1', 'infra_event', ['duration' => 0.002]),
                    self::open('sib_2', 'second'),
                ], self::close('parent_close_1', 'parent_close', ['status' => 'done'])),
            ],
        ]);

        $this->assertSame('parent_close', $tree->closeType);
        $this->assertCount(3, $tree->children);
        $this->assertSame('first', $tree->children[0]->type);
        $this->assertFalse($tree->children[0]->isEvent);
        $this->assertSame('infra_event', $tree->children[1]->type);
        $this->assertTrue($tree->children[1]->isEvent);
        $this->assertSame(0.002, $tree->children[1]->executionTime);
        $this->assertSame('second', $tree->children[2]->type);
        $this->assertNull($tree->children[2]->closeType);
    }

    public function testNestedCloseIsSetOnItsOwnOpen(): void
    {
        $tree = (new LogDataParser())->parseLogData([
            'entries' => [
                self::open('outer_1', 'outer_open', [], [
                    self::open('inner_1', 'inner_open', [], [], self::close('close_inner', 'inner_close', ['inner' => true])),
                ], self::close('close_outer', 'outer_close', ['outer' => true])),
            ],
        ]);

        $this->assertSame('outer_close', $tree->closeType);
        $this->assertSame(['outer' => true], $tree->closeContext);

        $this->assertCount(1, $tree->children);
        $inner = $tree->children[0];
        $this->assertSame('inner_close', $inner->closeType);
        $this->assertSame(['inner' => true], $inner->closeContext);
    }

    public function testMultipleRootsAreWrappedInSyntheticForestRoot(): void
    {
        $tree = (new LogDataParser())->parseLogData([
            'entries' => [
                self::open('a_1', 'a'),
                self::open('b_1', 'b'),
            ],
        ]);

        $this->assertTrue($tree->isSynthetic);
        $this->assertCount(2, $tree->children);
        $this->assertSame('a', $tree->children[0]->type);
        $this->assertSame('b', $tree->children[1]->type);
    }

    public function testRootEventsAndOpensKeepRecordedOrderInForest(): void
    {
        $tree = (new LogDataParser())->parseLogData([
            'entries' => [
                self::event('notice_1', 'notice', ['message' => 'before']),
                self::open('a_1', 'a', [], [], self::close('c1')),
                self::event('notice_2', 'notice', ['message' => 'after']),
            ],
        ]);

        $this->assertTrue($tree->isSynthetic);
        $this->assertCount(3, $tree->children);
        $this->assertTrue($tree->children[0]->isEvent);
        $this->assertFalse($tree->children[1]->isEvent);
        $this->assertTrue($tree->children[2]->isEvent);
        $this->assertSame(['message' => 'after'], $tree->children[2]->context);
    }

    public function testEventOnlyLogUsesSyntheticForestRoot(): void
    {
        $tree = (new LogDataParser())->parseLogData([
            'entries' => [self::event('notice_1', 'notice', ['message' => 'event only'])],
        ]);

        $this->assertTrue($tree->isSynthetic);
        $this->assertCount(1, $tree->children);
        $this->assertTrue($tree->children[0]->isEvent);
        $this->assertSame('notice', $tree->children[0]->type);
    }

    public function testEmptyEntriesYieldEmptyForest(): void
    {
        $tree = (new LogDataParser())->parseLogData(['entries' => []]);

        $this->assertTrue($tree->isSynthetic);
        $this->assertEmpty($tree->children);
    }

    public function testUnclosedOpenHasNoCloseType(): void
    {
        $tree = (new LogDataParser())->parseLogData([
            'entries' => [self::open('operation_1', 'test_operation')],
        ]);

        $this->assertFalse($tree->isSynthetic);
        $this->assertNull($tree->closeType);
        $this->assertSame([], $tree->closeContext);
    }

    public function testParseEventInOpen(): void
    {
        $tree = (new LogDataParser())->parseLogData([
            'entries' => [
                self::open('operation_1', 'test_operation', [], [
                    self::event('event_1', 'test_event', ['duration' => 0.003]),
                ], self::close('close_1')),
            ],
        ]);

        $this->assertSame('operation_1', $tree->id);
        $this->assertCount(1, $tree->children);

        $event = $tree->children[0];
        $this->assertSame('event_1', $event->id);
        $this->assertSame('test_event', $event->type);
        $this->assertSame(0.003, $event->executionTime);
        $this->assertTrue($event->isEvent);
    }

    public function testParseMultipleEvents(): void
    {
        $tree = (new LogDataParser())->parseLogData([
            'entries' => [
                self::open('operation_1', 'test_operation', [], [
                    self::event('event_1', 'first_event', ['duration' => 0.002]),
                    self::event('event_2', 'second_event', ['duration' => 0.004]),
                ], self::close('close_1')),
            ],
        ]);

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

            $tree = (new LogDataParser())->parseLogData([
                'entries' => [self::open('test_1', 'test_operation', $testCase, [], self::close('close_1'))],
            ]);

            $this->assertSame(
                $expected,
                $tree->executionTime,
                'Failed for context: ' . json_encode($testCase),
            );
        }
    }

    public function testInvalidLogDataMissingEntries(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid log data: missing entries section');

        (new LogDataParser())->parseLogData(['close' => []]);
    }

    public function testInvalidLogDataInvalidEntries(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid log data: missing entries section');

        (new LogDataParser())->parseLogData(['entries' => 'invalid']);
    }

    public function testEventWithNonArrayContext(): void
    {
        $event = self::event('event_1', 'test_event');
        $event['context'] = 'invalid_context';

        $tree = (new LogDataParser())->parseLogData([
            'entries' => [self::open('operation_1', 'test_operation', [], [$event], self::close('close_1'))],
        ]);

        $this->assertCount(1, $tree->children);
        $this->assertSame('test_event', $tree->children[0]->type);
        $this->assertSame([], $tree->children[0]->context);
    }

    public function testOpenEntryWithNonArrayContext(): void
    {
        $open = self::open('operation_1', 'test_operation', [], [], self::close('close_1'));
        $open['context'] = 'invalid_context';

        $tree = (new LogDataParser())->parseLogData(['entries' => [$open]]);

        $this->assertSame('test_operation', $tree->type);
        $this->assertSame([], $tree->context);
    }

    public function testOpenEntryWithMissingFields(): void
    {
        $tree = (new LogDataParser())->parseLogData([
            'entries' => [['kind' => 'open', 'schemaUrl' => 'test.json']],
        ]);

        $this->assertFalse($tree->isSynthetic);
        $this->assertSame('unknown', $tree->id);
        $this->assertSame('unknown', $tree->type);
        $this->assertEmpty($tree->children);
    }

    public function testEventWithMissingFields(): void
    {
        $tree = (new LogDataParser())->parseLogData([
            'entries' => [
                self::open('operation_1', 'test_operation', [], [
                    ['kind' => 'event', 'schemaUrl' => 'test.json'],
                ], self::close('close_1')),
            ],
        ]);

        $this->assertCount(1, $tree->children);
        $this->assertSame('unknown', $tree->children[0]->id);
        $this->assertSame('unknown', $tree->children[0]->type);
        $this->assertTrue($tree->children[0]->isEvent);
    }

    public function testNonNumericExecutionTimeValues(): void
    {
        $tree = (new LogDataParser())->parseLogData([
            'entries' => [
                self::open('test_1', 'test_operation', ['executionTime' => 'not_a_number'], [], self::close('close_1')),
            ],
        ]);

        $this->assertSame(0.0, $tree->executionTime);
    }

    public function testOpenWithoutChildrenKey(): void
    {
        $open = self::open('operation_1', 'test_operation');
        unset($open['children']);

        $tree = (new LogDataParser())->parseLogData(['entries' => [$open]]);

        $this->assertSame('test_operation', $tree->type);
        $this->assertEmpty($tree->children);
    }

    public function testOpenWithInvalidChildrenValue(): void
    {
        $open = self::open('operation_1', 'test_operation');
        $open['children'] = 'not_an_array';

        $tree = (new LogDataParser())->parseLogData(['entries' => [$open]]);

        $this->assertSame('test_operation', $tree->type);
        $this->assertEmpty($tree->children);
    }

    public function testCloseContextIsSetOnOpen(): void
    {
        $tree = (new LogDataParser())->parseLogData([
            'entries' => [
                self::open('operation_1', 'process_open', [], [], self::close('close_1', 'process_close', ['result' => 'ok'])),
            ],
        ]);

        $this->assertSame('process_close', $tree->closeType);
        $this->assertSame(['result' => 'ok'], $tree->closeContext);
        $this->assertSame([], $tree->closeProfile);
    }

    public function testCloseProfileIsSetOnOpen(): void
    {
        $profile = [
            'wallTime' => 0.123,
            'xdebugTrace' => [
                ['path' => '/tmp/profile.xt'],
            ],
        ];
        $close = self::close('close_1', 'process_close', ['result' => 'ok']);
        $close['profile'] = $profile;

        $tree = (new LogDataParser())->parseLogData([
            'entries' => [self::open('operation_1', 'process_open', [], [], $close)],
        ]);

        $this->assertSame($profile, $tree->closeProfile);
    }

    public function testOrphanCloseIsAttachedAsDiagnosticNode(): void
    {
        $orphan = self::close('close_1', 'process_close', ['result' => 'ok']);
        $orphan['openId'] = 'does_not_exist';

        $tree = (new LogDataParser())->parseLogData([
            'entries' => [self::open('operation_1', 'process_open')],
            'close' => [$orphan],
        ]);

        $this->assertNull($tree->closeType);
        $this->assertTrue($tree->isSynthetic);
        $this->assertCount(2, $tree->children);
        $this->assertSame('process_open', $tree->children[0]->type);
        $this->assertNull($tree->children[0]->closeType);
        $this->assertSame('close_1', $tree->children[1]->id);
        $this->assertSame('process_close', $tree->children[1]->type);
        $this->assertTrue($tree->children[1]->isOrphanClose);
        $this->assertSame(['result' => 'ok'], $tree->children[1]->context);
    }

    public function testOrphanCloseFollowsAllRootEntries(): void
    {
        $tree = (new LogDataParser())->parseLogData([
            'entries' => [
                self::open('operation_1', 'process_open'),
                self::open('operation_2', 'other_open'),
            ],
            'close' => [self::close('close_1', 'process_close', ['result' => 'ok'])],
        ]);

        $this->assertTrue($tree->isSynthetic);
        $this->assertCount(3, $tree->children);
        $this->assertSame('close_1', $tree->children[2]->id);
        $this->assertTrue($tree->children[2]->isOrphanClose);
    }

    public function testOrphanCloseKeepsProfile(): void
    {
        $orphan = self::close('close_1', 'process_close');
        $orphan['profile'] = ['wallTime' => 0.5];

        $tree = (new LogDataParser())->parseLogData([
            'entries' => [self::open('operation_1', 'process_open', [], [], self::close('c1'))],
            'close' => [$orphan],
        ]);

        $this->assertTrue($tree->isSynthetic);
        $this->assertSame(['wallTime' => 0.5], $tree->children[1]->closeProfile);
    }

    public function testMissingOrphanCloseIdsReceiveUniqueFallbackIds(): void
    {
        $tree = (new LogDataParser())->parseLogData([
            'entries' => [self::open('operation_1', 'process_open')],
            'close' => [
                ['type' => 'process_close', 'schemaUrl' => 'test.json', 'context' => ['result' => 'first']],
                ['type' => 'process_close', 'schemaUrl' => 'test.json', 'context' => ['result' => 'second']],
            ],
        ]);

        $this->assertTrue($tree->isSynthetic);
        $this->assertCount(3, $tree->children);
        $this->assertSame('process_open', $tree->children[0]->type);
        $this->assertSame('orphan_close_1', $tree->children[1]->id);
        $this->assertSame('orphan_close_2', $tree->children[2]->id);
        $this->assertTrue($tree->children[1]->isOrphanClose);
        $this->assertTrue($tree->children[2]->isOrphanClose);
    }
}
