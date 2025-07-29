<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;

final class LogSessionTest extends TestCase
{
    public function testLogSessionWithMinimalData(): void
    {
        $open = new OpenCloseEntry('test_1', 'test', 'https://example.com/test.json', ['data' => 'value']);
        $close = new EventEntry('close_1', 'close', 'https://example.com/close.json', ['result' => 'success']);
        $session = new LogJson(
            'https://schema.example.com/log.json',
            $open,
            $close,
            [],
        );

        $this->assertSame('https://schema.example.com/log.json', $session->schemaUrl);
        // title removed in new API
        $this->assertSame('test_1', $session->open->id);
        $this->assertSame('test', $session->open->type);
        $this->assertEmpty($session->events);
        $this->assertSame('close_1', $session->close->id);

        // Test array serialization
        $array = $session->toArray();
        $this->assertArrayNotHasKey('events', $array);
        $this->assertArrayHasKey('close', $array);
    }

    public function testLogSessionWithCompleteData(): void
    {
        $open = new OpenCloseEntry('start_1', 'start', 'https://example.com/start.json', ['start' => true]);
        $events = [
            new EventEntry('event1_1', 'event1', 'https://example.com/event1.json', ['event' => 1]),
            new EventEntry('event2_1', 'event2', 'https://example.com/event2.json', ['event' => 2]),
        ];
        $close = new EventEntry('end_1', 'end', 'https://example.com/end.json', ['end' => true]);

        $session = new LogJson(
            'https://schema.example.com/complete.json',
            $open,
            $close,
            $events,
        );

        $this->assertSame('https://schema.example.com/complete.json', $session->schemaUrl);
        // title removed in new API
        $this->assertSame('start_1', $session->open->id);
        $this->assertSame('start', $session->open->type);
        $this->assertCount(2, $session->events);
        $this->assertSame('event1_1', $session->events[0]->id);
        $this->assertSame('event1', $session->events[0]->type);
        $this->assertSame('event2_1', $session->events[1]->id);
        $this->assertSame('event2', $session->events[1]->type);
        $this->assertSame('end_1', $session->close->id);
        $this->assertSame('end', $session->close->type);
    }
}
