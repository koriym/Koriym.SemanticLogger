<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;

final class NullSemanticLoggerTest extends TestCase
{
    public function testNoOpLoggerProducesAnEmptyLogSession(): void
    {
        $logger = new NullSemanticLogger();

        // open() returns protocol-valid ids so the open/close protocol stays
        // intact; event() and close() retain nothing.
        $firstId = $logger->open(new FakeContext('open'));
        $this->assertSame('noop_1', $firstId);
        $secondId = $logger->open(new FakeContext('open again'));
        $this->assertSame('noop_2', $secondId);

        $logger->event(new FakeContext('event'));
        $logger->close(new FakeContext('close'), $firstId);

        $log = $logger->flush();
        $this->assertSame([], $log->records);
        $this->assertSame([], $log->closes);
        $this->assertSame(
            ['$schema' => CoreSchema::LOG_URL, 'entries' => []],
            $log->toArray(),
        );

        // flush() resets the id sequence.
        $this->assertSame('noop_1', $logger->open(new FakeContext('after flush')));
    }

    public function testFlushKeepsLinks(): void
    {
        $links = [['rel' => 'source', 'href' => 'https://example.com/source']];

        $array = (new NullSemanticLogger())->flush($links)->toArray();

        $this->assertSame([], $array['entries']);
        $this->assertSame($links, $array['links'] ?? null);
    }
}
