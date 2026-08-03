<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use Koriym\SemanticLogger\Profiler\PhpProfile;
use Koriym\SemanticLogger\Profiler\XdebugTrace;
use Koriym\SemanticLogger\Profiler\XHProfResult;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

use function file_put_contents;
use function is_array;
use function is_float;
use function is_string;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class DevSemanticLoggerTest extends TestCase
{
    private DevSemanticLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new DevSemanticLogger(new SemanticLogger());
    }

    public function testCloseCapturesWallTimeInternally(): void
    {
        $openId = $this->logger->open(new FakeContext('start'));
        $this->logger->close(new FakeContext('end'), $openId);

        $wallTimes = $this->wallTimes($this->logger);

        $this->assertArrayHasKey($openId, $wallTimes);
        $this->assertGreaterThanOrEqual(0.0, $wallTimes[$openId]);
    }

    public function testNestedClosesSplitProfilerSegmentsPerOperation(): void
    {
        $outer = $this->logger->open(new FakeContext('outer'));
        $inner = $this->logger->open(new FakeContext('inner'));
        $this->logger->close(new FakeContext('inner-end'), $inner);
        $this->logger->close(new FakeContext('outer-end'), $outer);

        $xdebugSegments = $this->xdebugSegments($this->logger);
        $xhprofSegments = $this->xhprofSegments($this->logger);

        // Outer being is split by the nested inner open into two segments
        // (one before inner started, one after inner closed). Inner being has
        // exactly one contiguous segment. The segment array shape is invariant
        // whether or not Xdebug/XHProf extensions are actually loaded — no-op
        // instances still occupy a slot in the list.
        $this->assertCount(2, $xdebugSegments[$outer], 'outer being must split into 2 xdebug segments around the nested inner open');
        $this->assertCount(1, $xdebugSegments[$inner], 'inner being has a single xdebug segment');
        $this->assertCount(2, $xhprofSegments[$outer]);
        $this->assertCount(1, $xhprofSegments[$inner]);
    }

    public function testProfileIsOmittedFromJsonOutputWithoutProfilerData(): void
    {
        $openId = $this->logger->open(new FakeContext('test'));
        $this->logger->close(new FakeContext('done'), $openId);
        $this->seedProfileState($this->logger, $openId, 0.123, [new XdebugTrace()], [new XHProfResult()]);

        $logJson = $this->logger->flush();
        $array = $logJson->toArray();
        $close = $this->firstSerializedClose($array);

        $this->assertArrayNotHasKey('profile', $array, 'top-level profile must not exist');
        $this->assertArrayHasKey('open', $array);
        $this->assertArrayNotHasKey('profile', $close);
    }

    public function testProfileAppearsInJsonOutputUnderCloseWhenProfilerDataExists(): void
    {
        $openId = $this->logger->open(new FakeContext('test'));
        $this->logger->close(new FakeContext('done'), $openId);

        $xdebugPath = tempnam(sys_get_temp_dir(), 'xdebug_profile_test');
        $xhprofPath = tempnam(sys_get_temp_dir(), 'xhprof_profile_test');
        $this->assertIsString($xdebugPath);
        $this->assertIsString($xhprofPath);
        file_put_contents($xdebugPath, 'trace');
        file_put_contents($xhprofPath, '{"main()":{"wt":100}}');

        try {
            $this->seedProfileState(
                $this->logger,
                $openId,
                0.123,
                [new XdebugTrace('trace', $xdebugPath)],
                [new XHProfResult(['main()' => ['wt' => 100]], $xhprofPath)],
            );

            $logJson = $this->logger->flush();
            $close = $this->firstSerializedClose($logJson->toArray());
            $this->assertArrayHasKey('profile', $close);
            $profile = $this->profileFromClose($close);
            $this->assertSame(0.123, $profile['wallTime']);
            $this->assertSame([['path' => $xdebugPath]], $profile['xdebugTrace']);
            $this->assertSame([['path' => $xhprofPath]], $profile['xhprofProfile']);
        } finally {
            @unlink($xdebugPath);
            @unlink($xhprofPath);
        }
    }

    public function testProfileContainsOnlyAvailableProfilerSections(): void
    {
        $openId = $this->logger->open(new FakeContext('test'));
        $this->logger->close(new FakeContext('done'), $openId);

        $xhprofPath = tempnam(sys_get_temp_dir(), 'xhprof_profile_test');
        $this->assertIsString($xhprofPath);
        file_put_contents($xhprofPath, '{"main()":{"wt":100}}');

        try {
            $this->seedProfileState(
                $this->logger,
                $openId,
                0.123,
                [new XdebugTrace()],
                [new XHProfResult(['main()' => ['wt' => 100]], $xhprofPath)],
            );

            $logJson = $this->logger->flush();
            $profile = $this->profileFromClose($this->firstSerializedClose($logJson->toArray()));

            $this->assertSame(0.123, $profile['wallTime']);
            $this->assertArrayHasKey('xhprofProfile', $profile);
            $this->assertArrayNotHasKey('xdebugTrace', $profile);
        } finally {
            @unlink($xhprofPath);
        }
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

        $this->assertCount(1, $first->close);
        $this->assertCount(1, $second->close);
        $this->assertSame($id1, $first->close[0]->openId);
        $this->assertSame($id2, $second->close[0]->openId);
    }

    public function testStrictCloseFailureKeepsProfilerAndInnerStacksRetryable(): void
    {
        $openId = $this->logger->open(new FakeContext('start'));
        $reflection = new ReflectionClass($this->logger);
        /** @var array<string, PhpProfile> $startedPhp */
        $startedPhp = $reflection->getProperty('startedPhp')->getValue($this->logger);
        $initialProfile = $startedPhp[$openId];

        try {
            $this->logger->close(new ModeThrowingContext(), $openId);
            $this->fail('Expected serialization failure.');
        } catch (ModeTestSerializationException) {
        }

        /** @var array<string, PhpProfile> $startedPhp */
        $startedPhp = $reflection->getProperty('startedPhp')->getValue($this->logger);
        $this->assertNotSame($initialProfile, $startedPhp[$openId]);

        $this->logger->close(new FakeContext('done'), $openId);
        $array = $this->logger->flush()->toArray();

        $this->assertSame($openId, $array['open'][0]['id']);
        $this->assertSame('example_event_2', $this->firstSerializedClose($array)['id']);
    }

    public function testTotalWrongCloseIdDoesNotCorruptProfilerStack(): void
    {
        $logger = new DevSemanticLogger(new SemanticLogger(SemanticLoggerMode::Total));
        $outerId = $logger->open(new FakeContext('outer'));
        $innerId = $logger->open(new FakeContext('inner'));

        $logger->close(new FakeContext('wrong order'), $outerId);
        $logger->close(new FakeContext('inner close'), $innerId);
        $logger->close(new FakeContext('outer close'), $outerId);

        $array = $logger->flush()->toArray();
        $this->assertSame($outerId, $array['open'][0]['id']);
        $this->assertSame($innerId, $this->valueAt($array, 'open', 0, 'open', 0, 'id'));
        $this->assertSame('close_id_mismatch', $this->valueAt($array, 'open', 0, 'open', 0, 'events', 0, 'context', 'kind'));
    }

    public function testTotalCloseFailureCommitsPlaceholderWithProfileTopologyIntact(): void
    {
        $logger = new DevSemanticLogger(new SemanticLogger(SemanticLoggerMode::Total));
        $openId = $logger->open(new FakeContext('start'));

        $logger->close(new ModeThrowingContext(), $openId);
        $array = $logger->flush()->toArray();

        $this->assertSame('semantic_logger_invalid_context', $this->firstSerializedClose($array)['type']);
        $this->assertSame('context_serialization_failed', $this->valueAt($array, 'open', 0, 'events', 0, 'context', 'kind'));
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

    /**
     * @param list<XdebugTrace>  $xdebugSegments
     * @param list<XHProfResult> $xhprofSegments
     */
    private function seedProfileState(
        DevSemanticLogger $logger,
        string $openId,
        float $wallTime,
        array $xdebugSegments,
        array $xhprofSegments,
    ): void {
        $reflection = new ReflectionClass($logger);

        $reflection->getProperty('wallTimes')->setValue($logger, [$openId => $wallTime]);
        $reflection->getProperty('xdebugSegments')->setValue($logger, [$openId => $xdebugSegments]);
        $reflection->getProperty('xhprofSegments')->setValue($logger, [$openId => $xhprofSegments]);
    }

    /** @return array<string, float> */
    private function wallTimes(DevSemanticLogger $logger): array
    {
        $value = (new ReflectionClass($logger))->getProperty('wallTimes')->getValue($logger);
        if (! is_array($value)) {
            $this->fail('Expected wallTimes to be an array.');
        }

        $wallTimes = [];
        foreach ($value as $openId => $wallTime) {
            if (! is_string($openId) || ! is_float($wallTime)) {
                $this->fail('Expected wallTimes to be keyed by string with float values.');
            }

            $wallTimes[$openId] = $wallTime;
        }

        return $wallTimes;
    }

    /** @return array<string, list<XdebugTrace>> */
    private function xdebugSegments(DevSemanticLogger $logger): array
    {
        $value = (new ReflectionClass($logger))->getProperty('xdebugSegments')->getValue($logger);
        if (! is_array($value)) {
            $this->fail('Expected xdebugSegments to be an array.');
        }

        $segments = [];
        foreach ($value as $openId => $entries) {
            if (! is_string($openId) || ! is_array($entries)) {
                $this->fail('Expected xdebugSegments entries to be lists keyed by string.');
            }

            $segmentList = [];
            foreach ($entries as $entry) {
                if (! $entry instanceof XdebugTrace) {
                    $this->fail('Expected xdebugSegments entries to be XdebugTrace instances.');
                }

                $segmentList[] = $entry;
            }

            $segments[$openId] = $segmentList;
        }

        return $segments;
    }

    /** @return array<string, list<XHProfResult>> */
    private function xhprofSegments(DevSemanticLogger $logger): array
    {
        $value = (new ReflectionClass($logger))->getProperty('xhprofSegments')->getValue($logger);
        if (! is_array($value)) {
            $this->fail('Expected xhprofSegments to be an array.');
        }

        $segments = [];
        foreach ($value as $openId => $entries) {
            if (! is_string($openId) || ! is_array($entries)) {
                $this->fail('Expected xhprofSegments entries to be lists keyed by string.');
            }

            $segmentList = [];
            foreach ($entries as $entry) {
                if (! $entry instanceof XHProfResult) {
                    $this->fail('Expected xhprofSegments entries to be XHProfResult instances.');
                }

                $segmentList[] = $entry;
            }

            $segments[$openId] = $segmentList;
        }

        return $segments;
    }

    /**
     * @param array<string, mixed> $serializedLog
     *
     * @return array<string, mixed>
     */
    private function firstSerializedClose(array $serializedLog): array
    {
        $openEntries = $serializedLog['open'] ?? null;
        if (is_array($openEntries)) {
            foreach ($openEntries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $close = $this->findFirstNestedClose($entry);
                if ($close !== null) {
                    return $close;
                }
            }
        }

        $closeEntries = $serializedLog['close'] ?? null;
        if (is_array($closeEntries)) {
            foreach ($closeEntries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                return $this->normalizeSerializedEntry($entry);
            }
        }

        $this->fail('Expected serialized log to contain a close entry.');
    }

    /**
     * @param array<mixed> $entry
     *
     * @return array<string, mixed>|null
     */
    private function findFirstNestedClose(array $entry): array|null
    {
        $close = $entry['close'] ?? null;
        if (is_array($close)) {
            return $this->normalizeSerializedEntry($close);
        }

        $children = $entry['open'] ?? null;
        if (! is_array($children)) {
            return null;
        }

        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }

            $childClose = $this->findFirstNestedClose($child);
            if ($childClose !== null) {
                return $childClose;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function normalizeSerializedEntry(array $entry): array
    {
        $normalized = [];
        foreach ($entry as $key => $value) {
            if (! is_string($key)) {
                $this->fail('Expected serialized close keys to be strings.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $close
     *
     * @return array<string, mixed>
     */
    private function profileFromClose(array $close): array
    {
        $profile = $close['profile'] ?? null;
        if (! is_array($profile)) {
            $this->fail('Expected serialized close to contain an array profile.');
        }

        $normalized = [];
        foreach ($profile as $key => $value) {
            if (! is_string($key)) {
                $this->fail('Expected serialized profile keys to be strings.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /** @param array<array-key, mixed> $array */
    private function valueAt(array $array, int|string ...$path): mixed
    {
        $value = $array;
        foreach ($path as $key) {
            $this->assertIsArray($value);
            $this->assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }
}
