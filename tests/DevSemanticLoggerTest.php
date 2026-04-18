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
        $this->assertArrayHasKey($id1, $logJson->profile->operationWallTimes);
        $this->assertArrayHasKey($id2, $logJson->profile->operationWallTimes);
        $this->assertGreaterThanOrEqual(0.0, $logJson->profile->operationWallTimes[$id1]);
        $this->assertGreaterThanOrEqual(0.0, $logJson->profile->operationWallTimes[$id2]);
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
        $this->assertCount(1, $first->profile->operationWallTimes);
        $this->assertCount(1, $second->profile->operationWallTimes);
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
        $this->assertSame([], $reflection->getProperty('started')->getValue($logger));
        $this->assertSame([], $reflection->getProperty('wallTimes')->getValue($logger));
        $this->assertNull($reflection->getProperty('xdebug')->getValue($logger));
        $this->assertSame(0, $reflection->getProperty('depth')->getValue($logger));
    }
}
