<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Profiler;

use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function is_array;
use function is_string;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class OperationProfileTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $op = new OperationProfile(wallTime: 0.002);

        $this->assertSame(0.002, $op->wallTime);
        $this->assertSame([], $op->xdebugTrace);
        $this->assertSame([], $op->xhprofProfile);
    }

    public function testJsonSerializeWithEmptySegments(): void
    {
        $op = new OperationProfile(wallTime: 0.001);
        $serialized = $op->jsonSerialize();

        $this->assertSame(0.001, $serialized['wallTime']);
        $this->assertArrayNotHasKey('xdebugTrace', $serialized);
        $this->assertArrayNotHasKey('xhprofProfile', $serialized);
        $this->assertFalse($op->hasProfilerData());
    }

    public function testJsonSerializeFiltersSegmentsWithoutPath(): void
    {
        // Segments with null filePath (no-op instances from unavailable extensions)
        // must not leak into the serialized output — the schema requires a path.
        $emptyXdebug = new XdebugTrace();
        $emptyXhprof = new XHProfResult();

        $op = new OperationProfile(
            wallTime: 0.003,
            xdebugTrace: [$emptyXdebug],
            xhprofProfile: [$emptyXhprof],
        );

        $serialized = $op->jsonSerialize();

        $this->assertArrayNotHasKey('xdebugTrace', $serialized);
        $this->assertArrayNotHasKey('xhprofProfile', $serialized);
        $this->assertFalse($op->hasProfilerData());
    }

    public function testJsonSerializeEmitsXdebugSegments(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'operation_profile_test');
        $testContent = 'trace content';
        file_put_contents($tempFile, $testContent);

        try {
            $xdebug = new XdebugTrace($testContent, $tempFile);
            $op = new OperationProfile(wallTime: 0.0, xdebugTrace: [$xdebug]);

            $serialized = $op->jsonSerialize();
            $xdebugTrace = $this->profilerSection($serialized, 'xdebugTrace');

            $this->assertSame(0.0, $serialized['wallTime']);
            $this->assertCount(1, $xdebugTrace);
            $this->assertSame($tempFile, $xdebugTrace[0]['path']);
            $this->assertSame(['path' => $tempFile], $xdebugTrace[0]);
            $this->assertArrayNotHasKey('xhprofProfile', $serialized);
            $this->assertTrue($op->hasProfilerData());
        } finally {
            unlink($tempFile);
        }
    }

    public function testJsonSerializeEmitsXhprofSegments(): void
    {
        $xhprof = new XHProfResult(['func' => ['wt' => 100]], '/tmp/xhprof-1.json');
        $op = new OperationProfile(wallTime: 0.0, xhprofProfile: [$xhprof]);

        $serialized = $op->jsonSerialize();
        $xhprofSection = $this->profilerSection($serialized, 'xhprofProfile');

        $this->assertSame(0.0, $serialized['wallTime']);
        $this->assertCount(1, $xhprofSection);
        $this->assertSame('/tmp/xhprof-1.json', $xhprofSection[0]['path']);
        $this->assertArrayNotHasKey('xdebugTrace', $serialized);
        $this->assertTrue($op->hasProfilerData());
    }

    public function testJsonSerializeEmitsMultipleSegmentsInOrder(): void
    {
        $x1 = new XHProfResult(['seg1' => ['wt' => 10]], '/tmp/xhprof-a.json');
        $x2 = new XHProfResult(['seg2' => ['wt' => 20]], '/tmp/xhprof-b.json');

        $op = new OperationProfile(wallTime: 0.0, xhprofProfile: [$x1, $x2]);
        $serialized = $op->jsonSerialize();
        $xhprofSection = $this->profilerSection($serialized, 'xhprofProfile');

        $this->assertSame(0.0, $serialized['wallTime']);
        $this->assertCount(2, $xhprofSection);
        $this->assertSame('/tmp/xhprof-a.json', $xhprofSection[0]['path']);
        $this->assertSame('/tmp/xhprof-b.json', $xhprofSection[1]['path']);
        $this->assertTrue($op->hasProfilerData());
    }

    /**
     * @param array<string, mixed> $serialized
     *
     * @return list<array{path: string}>
     */
    private function profilerSection(array $serialized, string $key): array
    {
        $section = $serialized[$key] ?? null;
        if (! is_array($section)) {
            $this->fail("Expected '{$key}' section to be an array.");
        }

        $normalized = [];
        foreach ($section as $entry) {
            if (! is_array($entry) || ! is_string($entry['path'] ?? null)) {
                $this->fail("Expected '{$key}' entries to contain a string path.");
            }

            $normalized[] = ['path' => $entry['path']];
        }

        return $normalized;
    }
}
