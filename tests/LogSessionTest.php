<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;

final class LogSessionTest extends TestCase
{
    public function testLogSessionWithMinimalData(): void
    {
        $open = new OpenCloseEntry('test_1', 'test', 'https://example.com/test.json', ['data' => 'value']);
        $close = new EventEntry('close_1', 'close', 'https://example.com/close.json', ['result' => 'success'], 'test_1');
        $session = new LogJson(
            'https://schema.example.com/log.json',
            [$open],
            [$close],
            [],
        );

        $this->assertSame('https://schema.example.com/log.json', $session->schemaUrl);
        $this->assertCount(1, $session->open);
        $this->assertSame('test_1', $session->open[0]->id);
        $this->assertSame('test', $session->open[0]->type);
        $this->assertEmpty($session->events);
        $this->assertCount(1, $session->close);
        $this->assertSame('close_1', $session->close[0]->id);

        // Test array serialization
        $array = $session->toArray();
        $openEntry = $this->treeOpenEntries($array)[0];
        $this->assertArrayNotHasKey('events', $array);
        $this->assertArrayNotHasKey('close', $array);
        $this->assertSame('close_1', $this->entryClose($openEntry)['id']);
    }

    public function testLogSessionWithCompleteData(): void
    {
        $open = new OpenCloseEntry('start_1', 'start', 'https://example.com/start.json', ['start' => true]);
        $events = [
            new EventEntry('event1_1', 'event1', 'https://example.com/event1.json', ['event' => 1]),
            new EventEntry('event2_1', 'event2', 'https://example.com/event2.json', ['event' => 2]),
        ];
        $close = new EventEntry('end_1', 'end', 'https://example.com/end.json', ['end' => true], 'start_1');

        $session = new LogJson(
            'https://schema.example.com/complete.json',
            [$open],
            [$close],
            $events,
        );

        $this->assertSame('https://schema.example.com/complete.json', $session->schemaUrl);
        $this->assertSame('start_1', $session->open[0]->id);
        $this->assertSame('start', $session->open[0]->type);
        $this->assertCount(2, $session->events);
        $this->assertSame('event1_1', $session->events[0]->id);
        $this->assertSame('event1', $session->events[0]->type);
        $this->assertSame('event2_1', $session->events[1]->id);
        $this->assertSame('event2', $session->events[1]->type);
        $this->assertSame('end_1', $session->close[0]->id);
        $this->assertSame('end', $session->close[0]->type);

        $array = $session->toArray();
        $this->assertCount(2, $this->treeEvents($array));
        $this->assertSame('end_1', $this->entryClose($this->treeOpenEntries($array)[0])['id']);
    }

    public function testToTreeArrayPreservesOrphanEventsAtTopLevel(): void
    {
        $open = new OpenCloseEntry('start_1', 'start', 'https://example.com/start.json', ['start' => true]);
        $events = [
            new EventEntry('nested_1', 'nested', 'https://example.com/nested.json', ['event' => 'nested'], 'start_1'),
            new EventEntry('orphan_1', 'orphan', 'https://example.com/orphan.json', ['event' => 'orphan'], 'missing_1'),
            new EventEntry('top_1', 'top', 'https://example.com/top.json', ['event' => 'top']),
        ];
        $close = new EventEntry('end_1', 'end', 'https://example.com/end.json', ['end' => true], 'start_1');

        $session = new LogJson(
            'https://schema.example.com/complete.json',
            [$open],
            [$close],
            $events,
        );

        $tree = $session->toTreeArray();
        $topLevelEvents = $this->treeEvents($tree);
        $openEntries = $this->treeOpenEntries($tree);
        $rootEntry = $openEntries[0];
        $nestedEvents = $this->entryEvents($rootEntry);

        $this->assertCount(1, $openEntries);
        $this->assertCount(2, $topLevelEvents);
        $this->assertSame('orphan_1', $this->entryId($topLevelEvents[0]));
        $this->assertSame('top_1', $this->entryId($topLevelEvents[1]));
        $this->assertCount(1, $nestedEvents);
        $this->assertSame('nested_1', $this->entryId($nestedEvents[0]));
    }

    public function testToTreeArrayPreservesOrphanClosesAtTopLevel(): void
    {
        $open = new OpenCloseEntry('start_1', 'start', 'https://example.com/start.json', ['start' => true]);
        $closes = [
            new EventEntry('end_1', 'end', 'https://example.com/end.json', ['end' => true], 'start_1'),
            new EventEntry('orphan_close_1', 'orphan_close', 'https://example.com/orphan-close.json', ['end' => false], 'missing_1'),
        ];

        $session = new LogJson(
            'https://schema.example.com/complete.json',
            [$open],
            $closes,
            [],
        );

        $tree = $session->toTreeArray();
        $openEntries = $this->treeOpenEntries($tree);
        $orphanCloses = $this->treeCloses($tree);

        $this->assertSame('end_1', $this->entryClose($openEntries[0])['id']);
        $this->assertArrayHasKey('close', $tree);
        $this->assertCount(1, $orphanCloses);
        $this->assertSame('orphan_close_1', $orphanCloses[0]['id']);
        $this->assertSame('missing_1', $orphanCloses[0]['openId']);
    }

    public function testPartitionClosesKeepsFirstMatchAndSendsDuplicatesToOrphans(): void
    {
        $open = new OpenCloseEntry('start_1', 'start', 'https://example.com/start.json', ['start' => true]);
        $closes = [
            new EventEntry('end_1', 'end', 'https://example.com/end.json', ['end' => true], 'start_1'),
            new EventEntry('end_duplicate_1', 'end', 'https://example.com/end.json', ['end' => false], 'start_1'),
        ];

        $session = new LogJson(
            'https://schema.example.com/complete.json',
            [$open],
            $closes,
            [],
        );

        $tree = $session->toArray();
        $openEntries = $this->treeOpenEntries($tree);
        $orphanCloses = $this->treeCloses($tree);

        $this->assertSame('end_1', $this->entryClose($openEntries[0])['id']);
        $this->assertCount(1, $orphanCloses);
        $this->assertSame('end_duplicate_1', $orphanCloses[0]['id']);
        $this->assertSame('start_1', $orphanCloses[0]['openId']);
    }

    /**
     * @param array<string, mixed> $tree
     *
     * @return list<array<string, mixed>>
     */
    private function treeOpenEntries(array $tree): array
    {
        $this->assertArrayHasKey('open', $tree);
        $openEntries = $tree['open'];
        $this->assertIsArray($openEntries);

        $validatedOpenEntries = [];
        foreach ($openEntries as $openEntry) {
            $this->assertIsArray($openEntry);
            $validatedOpenEntries[] = $this->normalizeEntry($openEntry);
        }

        return $validatedOpenEntries;
    }

    /**
     * @param array<string, mixed> $tree
     *
     * @return list<array<string, mixed>>
     */
    private function treeEvents(array $tree): array
    {
        $this->assertArrayHasKey('events', $tree);
        $events = $tree['events'];
        $this->assertIsArray($events);

        $validatedEvents = [];
        foreach ($events as $event) {
            $this->assertIsArray($event);
            $validatedEvents[] = $this->normalizeEntry($event);
        }

        return $validatedEvents;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return list<array<string, mixed>>
     */
    private function entryEvents(array $entry): array
    {
        $this->assertArrayHasKey('events', $entry);
        $events = $entry['events'];
        $this->assertIsArray($events);

        $validatedEvents = [];
        foreach ($events as $event) {
            $this->assertIsArray($event);
            $validatedEvents[] = $this->normalizeEntry($event);
        }

        return $validatedEvents;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function entryClose(array $entry): array
    {
        $this->assertArrayHasKey('close', $entry);
        $close = $entry['close'];
        $this->assertIsArray($close);

        return $this->normalizeEntry($close);
    }

    /**
     * @param array<string, mixed> $tree
     *
     * @return list<array<string, mixed>>
     */
    private function treeCloses(array $tree): array
    {
        $this->assertArrayHasKey('close', $tree);
        $closes = $tree['close'];
        $this->assertIsArray($closes);

        $validatedCloses = [];
        foreach ($closes as $close) {
            $this->assertIsArray($close);
            $validatedCloses[] = $this->normalizeEntry($close);
        }

        return $validatedCloses;
    }

    /** @param array<string, mixed> $entry */
    private function entryId(array $entry): string
    {
        $this->assertArrayHasKey('id', $entry);
        $this->assertIsString($entry['id']);

        return $entry['id'];
    }

    /**
     * @param array<mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function normalizeEntry(array $entry): array
    {
        $normalized = [];
        foreach ($entry as $key => $value) {
            $this->assertIsString($key);
            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
