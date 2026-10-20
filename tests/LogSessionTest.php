<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;

use function array_column;

final class LogSessionTest extends TestCase
{
    private const SCHEMA = 'https://schema.example.com/log.json';

    public function testOpenWithItsCloseIsNestedAndRootCloseIsOmitted(): void
    {
        $session = new LogJson(self::SCHEMA, [$this->open('test_1')], [$this->close('close_1', 'test_1')]);

        $this->assertSame(self::SCHEMA, $session->schemaUrl);
        $this->assertCount(1, $session->records);
        $this->assertCount(1, $session->closes);

        $array = $session->toArray();
        $this->assertSame(self::SCHEMA, $array['$schema']);
        $this->assertArrayNotHasKey('close', $array);
        $this->assertArrayNotHasKey('links', $array);
        $this->assertSame([
            [
                'kind' => 'open',
                'id' => 'test_1',
                'type' => 'test_1_type',
                'schemaUrl' => 'https://example.com/test_1.json',
                'context' => ['data' => 'value'],
                'children' => [],
                'close' => [
                    'id' => 'close_1',
                    'type' => 'close_1_type',
                    'schemaUrl' => 'https://example.com/close_1.json',
                    'context' => ['data' => 'value'],
                ],
            ],
        ], $array['entries']);
    }

    public function testEmptyLogHasEmptyEntries(): void
    {
        $this->assertSame(
            ['$schema' => self::SCHEMA, 'entries' => []],
            (new LogJson(self::SCHEMA, []))->toArray(),
        );
    }

    public function testUnclosedOpenHasNoCloseKey(): void
    {
        $entries = (new LogJson(self::SCHEMA, [$this->open('test_1')]))->toArray()['entries'];

        $this->assertArrayNotHasKey('close', $entries[0]);
    }

    public function testChildrenKeepRecordOrder(): void
    {
        $records = [
            $this->open('outer_1'),
            $this->event('first_1', 'outer_1'),
            $this->open('inner_1', 'outer_1'),
            $this->event('second_1', 'outer_1'),
        ];
        $closes = [$this->close('inner_end_1', 'inner_1'), $this->close('outer_end_1', 'outer_1')];

        $entries = (new LogJson(self::SCHEMA, $records, $closes))->toArray()['entries'];

        $this->assertCount(1, $entries);
        $children = $this->children($entries[0]);
        $this->assertSame(['first_1', 'inner_1', 'second_1'], $this->ids($children));
        $this->assertSame(['event', 'open', 'event'], $this->kinds($children));
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
        ];

        $entries = (new LogJson(self::SCHEMA, $records, [$this->close('end_1', 'start_1')]))->toArray()['entries'];

        $this->assertSame(['start_1', 'orphan_1', 'top_1'], $this->ids($entries));
        $this->assertSame(['open', 'event', 'event'], $this->kinds($entries));
        $this->assertSame(['nested_1'], $this->ids($this->children($entries[0])));
    }

    public function testCloseWithoutMatchingOpenBecomesRootOrphanWithOpenId(): void
    {
        $closes = [$this->close('end_1', 'start_1'), $this->close('orphan_close_1', 'missing_1')];

        $array = (new LogJson(self::SCHEMA, [$this->open('start_1')], $closes))->toArray();

        $orphans = $array['close'] ?? [];
        $this->assertSame('end_1', $array['entries'][0]['close']['id'] ?? null);
        $this->assertCount(1, $orphans);
        $this->assertSame('orphan_close_1', $orphans[0]['id']);
        $this->assertSame('missing_1', $orphans[0]['openId'] ?? null);
    }

    public function testSecondCloseOfTheSameOpenBecomesOrphan(): void
    {
        $closes = [$this->close('end_1', 'start_1'), $this->close('end_duplicate_1', 'start_1')];

        $array = (new LogJson(self::SCHEMA, [$this->open('start_1')], $closes))->toArray();

        $orphans = $array['close'] ?? [];
        $this->assertSame('end_1', $array['entries'][0]['close']['id'] ?? null);
        $this->assertCount(1, $orphans);
        $this->assertSame('end_duplicate_1', $orphans[0]['id']);
        $this->assertSame('start_1', $orphans[0]['openId'] ?? null);
    }

    public function testCloseWithoutOpenIdBecomesOrphanWithoutOpenId(): void
    {
        $array = (new LogJson(self::SCHEMA, [$this->open('start_1')], [$this->event('stray_close_1')]))->toArray();

        $orphans = $array['close'] ?? [];
        $this->assertArrayNotHasKey('close', $array['entries'][0]);
        $this->assertCount(1, $orphans);
        $this->assertSame('stray_close_1', $orphans[0]['id']);
        $this->assertArrayNotHasKey('openId', $orphans[0]);
    }

    public function testOpenNamingItselfAsParentIsPlacedAtTheRoot(): void
    {
        $array = (new LogJson(self::SCHEMA, [$this->open('loop_1', 'loop_1')]))->toArray();

        $this->assertSame(['loop_1'], $this->ids($array['entries']));
        $this->assertSame([], $this->children($array['entries'][0]));
    }

    public function testOpensInAParentCycleArePlacedAtTheRootWithTheirRecords(): void
    {
        $records = [$this->open('a_1', 'b_1'), $this->open('b_1', 'a_1'), $this->event('note_1', 'a_1')];

        $array = (new LogJson(self::SCHEMA, $records, [$this->close('end_1', 'b_1')]))->toArray();

        $this->assertSame(['a_1', 'b_1'], $this->ids($array['entries']));
        $this->assertSame(['note_1'], $this->ids($this->children($array['entries'][0])));
        $this->assertSame('end_1', $array['entries'][1]['close']['id'] ?? null);
        $this->assertArrayNotHasKey('close', $array);
    }

    public function testLinksArePresentOnlyWhenNotEmpty(): void
    {
        $links = [['rel' => 'source', 'href' => 'https://example.com/source']];

        $with = (new LogJson(self::SCHEMA, [], [], $links))->toArray();
        $without = (new LogJson(self::SCHEMA, [], [], []))->toArray();

        $this->assertSame($links, $with['links'] ?? null);
        $this->assertArrayNotHasKey('links', $without);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $session = new LogJson(self::SCHEMA, [$this->open('test_1')], [$this->close('close_1', 'test_1')]);

        $this->assertSame($session->toArray(), $session->jsonSerialize());
    }

    public function testRenderHandsLogToRenderer(): void
    {
        $session = new LogJson(self::SCHEMA, [$this->open('test_1')], [$this->close('close_1', 'test_1')]);

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

    private function open(string $id, string|null $parentId = null): OpenCloseEntry
    {
        return new OpenCloseEntry($id, $id . '_type', 'https://example.com/' . $id . '.json', ['data' => 'value'], $parentId);
    }

    private function event(string $id, string|null $openId = null): EventEntry
    {
        return new EventEntry($id, $id . '_type', 'https://example.com/' . $id . '.json', ['data' => 'value'], $openId);
    }

    private function close(string $id, string $openId): EventEntry
    {
        return $this->event($id, $openId);
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return list<array<string, mixed>>
     */
    private function children(array $entry): array
    {
        $raw = $entry['children'] ?? null;
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

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return list<mixed>
     */
    private function kinds(array $entries): array
    {
        return array_column($entries, 'kind');
    }
}
