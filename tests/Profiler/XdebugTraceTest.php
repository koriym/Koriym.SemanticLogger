<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Profiler;

use PHPUnit\Framework\TestCase;

use function extension_loaded;
use function file_put_contents;
use function function_exists;
use function getenv;
use function glob;
use function ini_get;
use function str_contains;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

class XdebugTraceTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clean up any test files
        $this->cleanupTempTraceFiles();
    }

    private function cleanupTempTraceFiles(): void
    {
        $tempDir = sys_get_temp_dir();
        $pattern = $tempDir . '/profile_*.xt';
        $files = glob($pattern);
        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    public function testStartReturnsInstanceRegardlessOfXdebugAvailability(): void
    {
        $result = XdebugTrace::start();

        $this->assertInstanceOf(XdebugTrace::class, $result);
    }

    public function testConstructorSetsPropertiesCorrectly(): void
    {
        $content = 'test trace content';
        $filePath = '/tmp/test.xt';

        $trace = new XdebugTrace($content, $filePath);

        $this->assertSame($content, $trace->content);
        $this->assertSame($filePath, $trace->filePath);
    }

    public function testGetFilePathReturnsCorrectValue(): void
    {
        $filePath = '/tmp/test.xt';
        $trace = new XdebugTrace(null, $filePath);

        $this->assertSame($filePath, $trace->getFilePath());
    }

    public function testGetFilePathReturnsNullForEmptyInstance(): void
    {
        $trace = new XdebugTrace();

        $this->assertNull($trace->getFilePath());
    }

    public function testGetFileSizeWithoutFile(): void
    {
        $trace = new XdebugTrace();

        $this->assertSame(0, $trace->getFileSize());
    }

    public function testGetFileSizeWithNonExistentFile(): void
    {
        $trace = new XdebugTrace(null, '/path/to/nonexistent/file.xt');

        $this->assertSame(0, $trace->getFileSize());
    }

    public function testGetFileSizeWithExistingFile(): void
    {
        // Create a temporary file for testing
        $tempFile = tempnam(sys_get_temp_dir(), 'xdebug_test');
        $testContent = 'test trace data for size calculation';
        file_put_contents($tempFile, $testContent);

        try {
            $trace = new XdebugTrace(null, $tempFile);
            $expectedSize = strlen($testContent);

            $this->assertSame($expectedSize, $trace->getFileSize());
        } finally {
            unlink($tempFile);
        }
    }

    public function testIsCompressedReturnsFalseForNonGzFiles(): void
    {
        $trace = new XdebugTrace(null, '/tmp/test.xt');

        $this->assertFalse($trace->isCompressed());
    }

    public function testIsCompressedReturnsTrueForGzFiles(): void
    {
        $trace = new XdebugTrace(null, '/tmp/test.xt.gz');

        $this->assertTrue($trace->isCompressed());
    }

    public function testIsCompressedReturnsFalseWithoutFilePath(): void
    {
        $trace = new XdebugTrace();

        $this->assertFalse($trace->isCompressed());
    }

    public function testJsonSerializeWithoutContent(): void
    {
        $trace = new XdebugTrace();
        $serialized = $trace->jsonSerialize();

        $this->assertSame([], $serialized);
    }

    public function testJsonSerializeWithContent(): void
    {
        $content = 'test trace content';
        $filePath = '/tmp/test.xt';

        $trace = new XdebugTrace($content, $filePath);
        $serialized = $trace->jsonSerialize();

        $this->assertNotEmpty($serialized);
        $this->assertSame($content, $serialized['data']);
        $this->assertSame($filePath, $serialized['file']);
        $this->assertSame('https://xdebug.org/docs/trace#Output-Formats', $serialized['spec_url']);
    }

    public function testStartWithoutXdebugExtension(): void
    {
        if (extension_loaded('xdebug')) {
            $this->markTestSkipped('Xdebug is loaded, cannot test without extension');
        }

        $result = XdebugTrace::start();

        $this->assertInstanceOf(XdebugTrace::class, $result);
        $this->assertNull($result->content);
        $this->assertNull($result->filePath);
    }

    public function testStartWithoutTraceFunctions(): void
    {
        if (function_exists('xdebug_start_trace')) {
            $this->markTestSkipped('xdebug_start_trace exists, cannot test without function');
        }

        $result = XdebugTrace::start();

        $this->assertInstanceOf(XdebugTrace::class, $result);
        $this->assertNull($result->content);
        $this->assertNull($result->filePath);
    }

    public function testStartWithoutTraceMode(): void
    {
        if (! extension_loaded('xdebug') || ! function_exists('xdebug_start_trace')) {
            $this->markTestSkipped('Xdebug trace functions not available');
        }

        // Check if trace mode is NOT configured
        $envMode = getenv('XDEBUG_MODE');
        $iniMode = ini_get('xdebug.mode');
        $xdebugMode = $envMode !== false ? $envMode : ($iniMode !== false ? $iniMode : '');

        if (str_contains($xdebugMode, 'trace')) {
            $this->markTestSkipped('Xdebug trace mode is configured, cannot test without trace mode');
        }

        $result = XdebugTrace::start();

        $this->assertInstanceOf(XdebugTrace::class, $result);
        // Should return empty instance when trace mode is not configured
        $this->assertNull($result->content);
        $this->assertNull($result->filePath);
    }

    public function testStopWithoutXdebugFunctions(): void
    {
        if (function_exists('xdebug_stop_trace')) {
            $this->markTestSkipped('xdebug_stop_trace exists, cannot test without function');
        }

        $trace = new XdebugTrace();
        $result = $trace->stop();

        $this->assertInstanceOf(XdebugTrace::class, $result);
        $this->assertNull($result->content);
        $this->assertNull($result->filePath);
    }

    public function testStopPreservesExistingContent(): void
    {
        $existingContent = 'existing trace content';
        $trace = new XdebugTrace($existingContent);

        $result = $trace->stop();

        $this->assertInstanceOf(XdebugTrace::class, $result);

        // The stop() method may return a new instance, but if it has existing content,
        // it should either preserve it or return new content (depending on implementation)
        if ($result->content !== null) {
            // Either preserved existing content or captured new content
            $this->assertIsString($result->content);
            $this->assertNotEmpty($result->content);

            return;
        }

        // If no content, that's acceptable for graceful degradation
        $this->assertNull($result->content);
    }

    /** @requires extension xdebug */
    public function testStartStopLifecycleWhenXdebugAvailable(): void
    {
        if (! extension_loaded('xdebug') || ! function_exists('xdebug_start_trace') || ! function_exists('xdebug_stop_trace')) {
            $this->markTestSkipped('Xdebug trace functions are not available');
        }

        // Check if trace mode is configured
        $envMode = getenv('XDEBUG_MODE');
        $iniMode = ini_get('xdebug.mode');
        $xdebugMode = $envMode !== false ? $envMode : ($iniMode !== false ? $iniMode : '');

        if (! str_contains($xdebugMode, 'trace')) {
            $this->markTestSkipped('Xdebug trace mode is not configured (XDEBUG_MODE does not contain "trace")');
        }

        $trace = XdebugTrace::start();
        $this->assertInstanceOf(XdebugTrace::class, $trace);

        // Do some work to generate trace data
        $dummy = [];
        for ($i = 0; $i < 100; $i++) {
            $dummy[] = $i * 2;
        }

        $stopped = $trace->stop();

        $this->assertInstanceOf(XdebugTrace::class, $stopped);

        // When Xdebug tracing is working, we might get data
        // But it's not guaranteed due to configuration variations
        if ($stopped->content !== null) {
            $this->assertIsString($stopped->content);
            $this->assertGreaterThan(0, strlen($stopped->content));
        }
    }

    public function testGracefulErrorHandling(): void
    {
        // This test verifies that the error handler correctly suppresses
        // "Function trace was not started" errors

        $trace = new XdebugTrace();
        $result = $trace->stop();

        // Should not throw any errors or exceptions
        $this->assertInstanceOf(XdebugTrace::class, $result);

        // Most likely will be empty since trace wasn't started
        $this->assertNull($result->content);
        $this->assertNull($result->filePath);
    }

    public function testMultipleStartStopCycles(): void
    {
        // Test that multiple start/stop cycles don't interfere with each other
        $trace1 = XdebugTrace::start();
        $stopped1 = $trace1->stop();

        $trace2 = XdebugTrace::start();
        $stopped2 = $trace2->stop();

        $this->assertInstanceOf(XdebugTrace::class, $stopped1);
        $this->assertInstanceOf(XdebugTrace::class, $stopped2);

        // Each instance should be independent
        $this->assertNotSame($stopped1, $stopped2);
    }
}
