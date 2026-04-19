<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

use function assert;

final class DevSemanticLoggerTest extends TestCase
{
    private DevSemanticLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new DevSemanticLogger(new SemanticLogger());
    }

    public function testCloseCarriesOwnProfile(): void
    {
        $openId = $this->logger->open(new FakeContext('start'));
        $this->logger->close(new FakeContext('end'), $openId);

        $logJson = $this->logger->flush();

        $this->assertNotNull($logJson->close->profile);
        $this->assertSame($openId, $logJson->close->openId);
        $this->assertGreaterThanOrEqual(0.0, $logJson->close->profile->wallTime);
    }

    public function testNestedClosesEachCarryTheirOwnProfile(): void
    {
        $outer = $this->logger->open(new FakeContext('outer'));
        $inner = $this->logger->open(new FakeContext('inner'));
        $this->logger->close(new FakeContext('inner-end'), $inner);
        $this->logger->close(new FakeContext('outer-end'), $outer);

        $logJson = $this->logger->flush();

        $outerClose = $logJson->close;
        $innerClose = $outerClose->close;
        assert($innerClose !== null);

        $this->assertSame($outer, $outerClose->openId);
        $this->assertSame($inner, $innerClose->openId);

        $this->assertNotNull($outerClose->profile);
        $this->assertNotNull($innerClose->profile);

        // Outer being is split by the nested inner open into two segments
        // (one before inner started, one after inner closed). Inner being has
        // exactly one contiguous segment. The segment array shape is invariant
        // whether or not Xdebug/XHProf extensions are actually loaded — no-op
        // instances still occupy a slot in the list.
        $this->assertCount(2, $outerClose->profile->xdebug, 'outer being must split into 2 xdebug segments around the nested inner open');
        $this->assertCount(1, $innerClose->profile->xdebug, 'inner being has a single xdebug segment');

        $this->assertCount(2, $outerClose->profile->xhprof);
        $this->assertCount(1, $innerClose->profile->xhprof);
    }

    public function testProfileAppearsInJsonOutputUnderClose(): void
    {
        $openId = $this->logger->open(new FakeContext('test'));
        $this->logger->close(new FakeContext('done'), $openId);

        $logJson = $this->logger->flush();
        $array = $logJson->toArray();

        $this->assertArrayNotHasKey('profile', $array, 'top-level profile must not exist');
        $this->assertArrayHasKey('close', $array);
        $close = $array['close'];
        $this->assertIsArray($close);
        $this->assertArrayHasKey('profile', $close);
        $profile = $close['profile'];
        $this->assertIsArray($profile);
        $this->assertArrayHasKey('wallTime', $profile);
        $this->assertArrayHasKey('xdebug', $profile);
        $this->assertArrayHasKey('xhprof', $profile);
    }

    public function testEventIsDelegated(): void
    {
        $openId = $this->logger->open(new FakeContext('start'));
        $this->logger->event(new FakeContext('mid'));
        $this->logger->close(new FakeContext('end'), $openId);

        $logJson = $this->logger->flush();

        $this->assertCount(1, $logJson->events);
    }

    public function testFlushResetsState(): void
    {
        $id1 = $this->logger->open(new FakeContext('first'));
        $this->logger->close(new FakeContext('done'), $id1);
        $first = $this->logger->flush();

        $id2 = $this->logger->open(new FakeContext('second'));
        $this->logger->close(new FakeContext('done'), $id2);
        $second = $this->logger->flush();

        // Each flush produces exactly one operation, and its close carries profile.
        $this->assertNotNull($first->close->profile);
        $this->assertNotNull($second->close->profile);
        $this->assertSame($id1, $first->close->openId);
        $this->assertSame($id2, $second->close->openId);
    }

    public function testDevStateIsResetEvenWhenInnerFlushThrows(): void
    {
        $throwingInner = new class implements SemanticLoggerInterface {
            public function open(AbstractContext $context): string
            {
                return 'fake_1';
            }

            public function close(AbstractContext $context, string $openId): void
            {
            }

            public function event(AbstractContext $context): void
            {
            }

            /** @param list<array{rel: string, href: string, title?: string, type?: string}> $links */
            public function flush(array $links = []): LogJson
            {
                throw new RuntimeException('inner flush failed');
            }
        };

        $logger = new DevSemanticLogger($throwingInner);
        $logger->open(new FakeContext('leaked'));

        try {
            $logger->flush();
            $this->fail('Expected RuntimeException from inner flush');
        } catch (RuntimeException) {
            // Expected.
        }

        // Dev-side state must have been reset by the finally block.
        $reflection = new ReflectionClass($logger);
        $this->assertSame([], $reflection->getProperty('startedPhp')->getValue($logger));
        $this->assertSame([], $reflection->getProperty('wallTimes')->getValue($logger));
        $this->assertSame([], $reflection->getProperty('xdebugSegments')->getValue($logger));
        $this->assertSame([], $reflection->getProperty('xhprofSegments')->getValue($logger));
        $this->assertSame([], $reflection->getProperty('openStack')->getValue($logger));
        $this->assertNull($reflection->getProperty('activeXdebug')->getValue($logger));
        $this->assertNull($reflection->getProperty('activeXhprof')->getValue($logger));
        $this->assertSame(0, $reflection->getProperty('depth')->getValue($logger));
    }
}
