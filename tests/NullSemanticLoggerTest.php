<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;

final class NullSemanticLoggerTest extends TestCase
{
    public function testNoOpLoggerProducesAnEmptyLogSession(): void
    {
        $logger = new NullSemanticLogger();

        $openId = $logger->open(new FakeContext('open'));
        $this->assertSame('', $openId, 'open() returns an empty id meaning "no close needed"');

        // event() and close() are no-ops and must not throw or change the (empty) session.
        $logger->event(new FakeContext('event'));
        $logger->close(new FakeContext('close'), $openId);

        $log = $logger->flush();
        $this->assertSame([], $log->open);
        $this->assertSame([], $log->close);
        $this->assertSame([], $log->events);
    }
}
