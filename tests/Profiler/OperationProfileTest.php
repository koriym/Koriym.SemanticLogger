<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Profiler;

use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class OperationProfileTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $op = new OperationProfile(wallTime: 0.002);

        $this->assertSame(0.002, $op->wallTime);
        $this->assertSame([], $op->xdebug);
        $this->assertSame([], $op->xhprof);
    }

    public function testJsonSerializeWithEmptySegments(): void
    {
        $op = new OperationProfile(wallTime: 0.001);
        $serialized = $op->jsonSerialize();

        $this->assertSame(0.001, $serialized['wallTime']);
        $this->assertSame([], $serialized['xdebug']);
        $this->assertSame([], $serialized['xhprof']);
    }

    public function testJsonSerializeFiltersSegmentsWithoutSource(): void
    {
        // Segments with null filePath (no-op instances from unavailable extensions)
        // must not leak into the serialized output — the schema requires a source.
        $emptyXdebug = new XdebugTrace();
        $emptyXhprof = new XHProfResult();

        $op = new OperationProfile(
            wallTime: 0.003,
            xdebug: [$emptyXdebug],
            xhprof: [$emptyXhprof],
        );

        $serialized = $op->jsonSerialize();

        $this->assertSame([], $serialized['xdebug']);
        $this->assertSame([], $serialized['xhprof']);
    }

    public function testJsonSerializeEmitsXdebugSegments(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'operation_profile_test');
        $testContent = 'trace content';
        file_put_contents($tempFile, $testContent);

        try {
            $xdebug = new XdebugTrace($testContent, $tempFile);
            $op = new OperationProfile(wallTime: 0.0, xdebug: [$xdebug]);

            $serialized = $op->jsonSerialize();

            $this->assertCount(1, $serialized['xdebug']);
            $this->assertSame($tempFile, $serialized['xdebug'][0]['source']);
            $this->assertSame(['source' => $tempFile], $serialized['xdebug'][0]);
        } finally {
            unlink($tempFile);
        }
    }

    public function testJsonSerializeEmitsXhprofSegments(): void
    {
        $xhprof = new XHProfResult(['func' => ['wt' => 100]], '/tmp/xhprof-1.json');
        $op = new OperationProfile(wallTime: 0.0, xhprof: [$xhprof]);

        $serialized = $op->jsonSerialize();

        $this->assertCount(1, $serialized['xhprof']);
        $this->assertSame('/tmp/xhprof-1.json', $serialized['xhprof'][0]['source']);
    }

    public function testJsonSerializeEmitsMultipleSegmentsInOrder(): void
    {
        $x1 = new XHProfResult(['seg1' => ['wt' => 10]], '/tmp/xhprof-a.json');
        $x2 = new XHProfResult(['seg2' => ['wt' => 20]], '/tmp/xhprof-b.json');

        $op = new OperationProfile(wallTime: 0.0, xhprof: [$x1, $x2]);
        $serialized = $op->jsonSerialize();

        $this->assertCount(2, $serialized['xhprof']);
        $this->assertSame('/tmp/xhprof-a.json', $serialized['xhprof'][0]['source']);
        $this->assertSame('/tmp/xhprof-b.json', $serialized['xhprof'][1]['source']);
    }
}
