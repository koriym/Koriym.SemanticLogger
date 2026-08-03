<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;

final class NullSemanticLoggerTest extends TestCase
{
    public function testNoOpLoggerProducesAnEmptyLogSession(): void
    {
        $logger = new NullSemanticLogger();

        $firstOpenId = $logger->open(new FakeContext('open'));
        $secondOpenId = $logger->open(new FakeContext('nested'));
        $this->assertSame('noop_1', $firstOpenId);
        $this->assertSame('noop_2', $secondOpenId);

        // event() and close() are no-ops and must not throw or change the (empty) session.
        $logger->event(new FakeContext('event'));
        $logger->close(new FakeContext('close'), $secondOpenId);
        $logger->close(new FakeContext('close'), $firstOpenId);

        $log = $logger->flush();
        $this->assertSame([], $log->open);
        $this->assertSame([], $log->close);
        $this->assertSame([], $log->events);

        $this->assertSame('noop_1', $logger->open(new FakeContext('next session')));
    }
}
