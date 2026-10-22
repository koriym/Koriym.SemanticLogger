<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;

use function array_column;

final class LogSessionTest extends TestCase
{
    private const SCHEMA = 'https://schema.example.com/log.json';

    public function testOpenWithItsCloseIsNestedAndNoLogKey(): void
    {
        $session = new LogJson(self::SCHEMA, [$this->open('test_1'), $this->close('close_1', 'test_1')]);

        $this->assertSame(self::SCHEMA, $session->schemaUrl);
        $this->assertCount(2, $session->records);

        $array = $session->toArray();
        $this->assertSame(self::SCHEMA, $array['$schema']);
        $this->assertArrayNotHasKey('links', $array);
        $this->assertSame([
            [
                'id' => 'test_1',
                'type' => 'test_1_type',
                'schemaUrl' => 'https://example.com/test_1.json',
                'context' => ['data' => 'value'],
                'close' => [
                    'id' => 'close_1',
                    'type' => 'close_1_type',
                    'schemaUrl' => 'https://example.com/close_1.json',
                    'context' => ['data' => 'value'],
                ],
            ],
        ], $array['log']);
    }

    public function testEmptyLogHasEmptyLog(): void
    {
        $this->assertSame(
            ['$schema' => self::SCHEMA, 'log' => []],
            (new LogJson(self::SCHEMA, []))->toArray(),
        );
    }

    public function testUnclosedOpenWithNoChildrenHasEmptyLogAndNoClose(): void
    {
        $record = (new LogJson(self::SCHEMA, [$this->open('test_1')]))->toArray()['log'][0];

        $this->assertSame([], $record['log'] ?? null);
        $this->assertArrayNotHasKey('close', $record);
    }

    public function testClosedOpenWithNoChildrenHasNoLogKey(): void
    {
        $record = (new LogJson(self::SCHEMA, [$this->open('test_1'), $this->close('close_1', 'test_1')]))->toArray()['log'][0];

        $this->assertArrayNotHasKey('log', $record);
    }

    public function testEventNeverHasALogKey(): void
    {
        $record = (new LogJson(self::SCHEMA, [$this->event('standalone_1')]))->toArray()['log'][0];

        $this->assertArrayNotHasKey('log', $record);
        $this->assertArrayNotHasKey('close', $record);
        $this->assertArrayNotHasKey('openId', $record);
    }

    public function testChildrenKeepRecordOrder(): void
    {
        $records = [
            $this->open('outer_1'),
            $this->event('first_1', 'outer_1'),
            $this->open('inner_1', 'outer_1'),
            $this->event('second_1', 'outer_1'),
            $this->close('inner_end_1', 'inner_1'),
            $this->close('outer_end_1', 'outer_1'),
        ];

        $log = (new LogJson(self::SCHEMA, $records))->toArray()['log'];

        $this->assertCount(1, $log);
        $children = $this->children($log[0]);
        $this->assertSame(['first_1', 'inner_1', 'second_1'], $this->ids($children));
        $this->assertArrayNotHasKey('openId', $children[0]);
        $innerClose = $children[1]['close'] ?? null;
        $this->assertIsArray($innerClose);
        $this->assertSame('inner_end_1', $innerClose['id'] ?? null);
        $this->assertArrayNotHasKey('openId', $innerClose);
    }

    public function testEventsWithoutAnOpenScopeStayAtTheRoot(): void
    {
        $records = [
            $this->open('start_1'),
            $this->event('nested_1', 'start_1'),
            $this->event('orphan_1', 'missing_1'),
            $this->event('top_1'),
            $this->close('end_1', 'start_1'),
        ];

        $log = (new LogJson(self::SCHEMA, $records))->toArray()['log'];

        $this->assertSame(['start_1', 'orphan_1', 'top_1'], $this->ids($log));
        $this->assertSame(['nested_1'], $this->ids($this->children($log[0])));
    }

    public function testOrphanCloseAppearsInRootLogAtEncounterPositionWithOpenId(): void
    {
        $records = [$this->open('start_1'), $this->close('end_1', 'start_1'), $this->close('orphan_close_1', 'missing_1')];

        $array = (new LogJson(self::SCHEMA, $records))->toArray();

        $log = $array['log'];
        $this->assertSame('end_1', $log[0]['close']['id'] ?? null);
        $this->assertSame(['start_1', 'orphan_close_1'], $this->ids($log));
        $this->assertSame('missing_1', $log[1]['openId'] ?? null);
        $this->assertArrayNotHasKey('close', $array);
    }

    public function testOrphanCloseIsSplicedAfterTheRootRecordBeingBuiltWhenEncountered(): void
    {
        $records = [
            $this->open('start_1'),
            $this->event('nested_1', 'start_1'),
            $this->close('orphan_close_1', 'missing_1'),
            $this->close('end_1', 'start_1'),
        ];

        $log = (new LogJson(self::SCHEMA, $records))->toArray()['log'];

        // The orphan was encountered while `start_1` was the root record
        // being built (a child was open under it), so it is spliced right
        // after the whole `start_1` subtree, not nested inside it.
        $this->assertSame(['start_1', 'orphan_close_1'], $this->ids($log));
        $this->assertSame(['nested_1'], $this->ids($this->children($log[0])));
    }

    public function testOrphanCloseEncounteredBeforeAnyRootRecordLeadsTheRootLog(): void
    {
        $records = [$this->close('orphan_close_1', 'missing_1'), $this->open('start_1'), $this->close('end_1', 'start_1')];

        $log = (new LogJson(self::SCHEMA, $records))->toArray()['log'];

        $this->assertSame(['orphan_close_1', 'start_1'], $this->ids($log));
        $this->assertSame('missing_1', $log[0]['openId'] ?? null);
    }

    public function testSecondCloseOfTheSameOpenBecomesOrphan(): void
    {
        $records = [$this->open('start_1'), $this->close('end_1', 'start_1'), $this->close('end_duplicate_1', 'start_1')];

        $log = (new LogJson(self::SCHEMA, $records))->toArray()['log'];

        $this->assertSame('end_1', $log[0]['close']['id'] ?? null);
        $this->assertSame(['start_1', 'end_duplicate_1'], $this->ids($log));
        $this->assertSame('start_1', $log[1]['openId'] ?? null);
    }

    public function testOpenNamingItselfAsParentIsPlacedAtTheRoot(): void
    {
        $log = (new LogJson(self::SCHEMA, [$this->open('loop_1', 'loop_1')]))->toArray()['log'];

        $this->assertSame(['loop_1'], $this->ids($log));
        $this->assertSame([], $log[0]['log'] ?? null);
    }

    public function testOpensInAParentCycleArePlacedAtTheRootWithTheirRecords(): void
    {
        $records = [$this->open('a_1', 'b_1'), $this->open('b_1', 'a_1'), $this->event('note_1', 'a_1'), $this->close('end_1', 'b_1')];

        $array = (new LogJson(self::SCHEMA, $records))->toArray();

        $this->assertSame(['a_1', 'b_1'], $this->ids($array['log']));
        $this->assertSame(['note_1'], $this->ids($this->children($array['log'][0])));
        $this->assertSame('end_1', $array['log'][1]['close']['id'] ?? null);
    }

    public function testLinksArePresentOnlyWhenNotEmpty(): void
    {
        $links = [['rel' => 'source', 'href' => 'https://example.com/source']];

        $with = (new LogJson(self::SCHEMA, [], $links))->toArray();
        $without = (new LogJson(self::SCHEMA, [], []))->toArray();

        $this->assertSame($links, $with['links'] ?? null);
        $this->assertArrayNotHasKey('links', $without);
    }

    public function testRenderHandsLogToRenderer(): void
    {
        $session = new LogJson(self::SCHEMA, [$this->open('test_1'), $this->close('close_1', 'test_1')]);

        $renderer = new class implements LogRendererInterface {
            public LogJson|null $received = null;

            public function render(LogJson $log): string
            {
                $this->received = $log;

                return 'rendered:' . $log->records[0]->id;
            }
        };

        $output = $session->render($renderer);

        // Double dispatch: the log hands itself back to the renderer unchanged.
        $this->assertSame($session, $renderer->received);
        $this->assertSame('rendered:test_1', $output);
    }

    private function open(string $id, string|null $parentId = null): OpenEntry
    {
        return new OpenEntry($id, $id . '_type', 'https://example.com/' . $id . '.json', ['data' => 'value'], $parentId);
    }

    private function event(string $id, string|null $openId = null): EventEntry
    {
        return new EventEntry($id, $id . '_type', 'https://example.com/' . $id . '.json', ['data' => 'value'], $openId);
    }

    private function close(string $id, string $openId): CloseEntry
    {
        return new CloseEntry($id, $id . '_type', 'https://example.com/' . $id . '.json', ['data' => 'value'], $openId);
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return list<array<string, mixed>>
     */
    private function children(array $entry): array
    {
        $raw = $entry['log'] ?? null;
        $this->assertIsArray($raw);

        $children = [];
        foreach ($raw as $child) {
            $this->assertIsArray($child);
            $normalized = [];
            foreach ($child as $key => $value) {
                $this->assertIsString($key);
                $normalized[$key] = $value;
            }

            $children[] = $normalized;
        }

        return $children;
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return list<mixed>
     */
    private function ids(array $entries): array
    {
        return array_column($entries, 'id');
    }
}
