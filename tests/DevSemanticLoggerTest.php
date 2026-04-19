<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class DevSemanticLoggerTest extends TestCase
{
    private DevSemanticLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new DevSemanticLogger(new SemanticLogger());
    }

    public function testFlushContainsProfile(): void
    {
        $openId = $this->logger->open(new FakeContext('start'));
        $this->logger->close(new FakeContext('end'), $openId);

        $logJson = $this->logger->flush();

        $this->assertNotNull($logJson->profile);
    }

    public function testPerOperationWallTimesAreRecorded(): void
    {
        $id1 = $this->logger->open(new FakeContext('outer'));
        $id2 = $this->logger->open(new FakeContext('inner'));
        $this->logger->close(new FakeContext('inner-end'), $id2);
        $this->logger->close(new FakeContext('outer-end'), $id1);

        $logJson = $this->logger->flush();

        $this->assertNotNull($logJson->profile);
        $this->assertArrayHasKey($id1, $logJson->profile->operations);
        $this->assertArrayHasKey($id2, $logJson->profile->operations);
        $this->assertGreaterThanOrEqual(0.0, $logJson->profile->operations[$id1]->wallTime);
        $this->assertGreaterThanOrEqual(0.0, $logJson->profile->operations[$id2]->wallTime);
    }

    public function testProfileAppearsInJsonOutput(): void
    {
        $openId = $this->logger->open(new FakeContext('test'));
        $this->logger->close(new FakeContext('done'), $openId);

        $logJson = $this->logger->flush();
        $array = $logJson->toArray();

        $this->assertArrayHasKey('profile', $array);
        $profile = $array['profile'];
        $this->assertIsArray($profile);
        $this->assertArrayHasKey('operations', $profile);
        $operations = $profile['operations'];
        $this->assertIsArray($operations);
        $this->assertArrayHasKey($openId, $operations);
        $opEntry = $operations[$openId];
        $this->assertIsArray($opEntry);
        $this->assertArrayHasKey('wallTime', $opEntry);
        $this->assertArrayHasKey('xdebug', $opEntry);
        $this->assertArrayHasKey('xhprof', $opEntry);
    }

    public function testNestedOpensProduceSegmentedProfiles(): void
    {
        $outer = $this->logger->open(new FakeContext('outer'));
        $inner = $this->logger->open(new FakeContext('inner'));
        $this->logger->close(new FakeContext('inner-end'), $inner);
        $this->logger->close(new FakeContext('outer-end'), $outer);

        $logJson = $this->logger->flush();

        $this->assertNotNull($logJson->profile);

        // Outer being is split by the nested inner open into two segments
        // (one before inner started, one after inner closed). Inner being has
        // exactly one contiguous segment. The segment array shape is invariant
        // whether or not Xdebug/XHProf extensions are actually loaded — no-op
        // instances still occupy a slot in the list.
        $outerProfile = $logJson->profile->operations[$outer];
        $innerProfile = $logJson->profile->operations[$inner];

        $this->assertCount(2, $outerProfile->xdebug, 'outer being must split into 2 xdebug segments around the nested inner open');
        $this->assertCount(1, $innerProfile->xdebug, 'inner being has a single xdebug segment');

        $this->assertCount(2, $outerProfile->xhprof, 'outer being must split into 2 xhprof segments around the nested inner open');
        $this->assertCount(1, $innerProfile->xhprof, 'inner being has a single xhprof segment');
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

        // Each flush produces exactly one operation entry
        $this->assertNotNull($first->profile);
        $this->assertNotNull($second->profile);
        $this->assertCount(1, $first->profile->operations);
        $this->assertCount(1, $second->profile->operations);
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
