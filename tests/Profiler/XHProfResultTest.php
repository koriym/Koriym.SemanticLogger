<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Profiler;

use PHPUnit\Framework\TestCase;

use function file_exists;
use function file_get_contents;
use function function_exists;
use function glob;
use function json_decode;
use function sys_get_temp_dir;
use function unlink;

class XHProfResultTest extends TestCase
{
    protected function setUp(): void
    {
        // Clean up any existing temporary files before each test
        $this->cleanupTempFiles();
    }

    protected function tearDown(): void
    {
        // Clean up temporary files after each test
        $this->cleanupTempFiles();
    }

    private function cleanupTempFiles(): void
    {
        $tempDir = sys_get_temp_dir();
        $pattern = $tempDir . '/xhprof_*.json';
        $files = glob($pattern);
        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    public function testStartReturnsInstanceRegardlessOfXhprofAvailability(): void
    {
        $result = XHProfResult::start();

        $this->assertInstanceOf(XHProfResult::class, $result);
        $this->assertNull($result->data);
        $this->assertNull($result->filePath);
    }

    public function testStopWithoutStartReturnsEmptyInstance(): void
    {
        $result = new XHProfResult();
        $stopped = $result->stop('test://uri');

        $this->assertInstanceOf(XHProfResult::class, $stopped);

        // Without XHProf enabled or no profiling data, should return empty
        if (! function_exists('xhprof_disable')) {
            $this->assertNull($stopped->data);
            $this->assertNull($stopped->filePath);
        }
    }

    public function testGetFilePathReturnsCorrectValue(): void
    {
        $filePath = '/tmp/test_file.json';
        $result = new XHProfResult([], $filePath);

        $this->assertSame($filePath, $result->filePath);
    }

    public function testGetFilePathReturnsNullForEmptyInstance(): void
    {
        $result = new XHProfResult();

        $this->assertNull($result->filePath);
    }

    public function testDataPropertyWithData(): void
    {
        $testData = [
            'func1' => ['wt' => 100],
            'func2' => ['wt' => 200],
            'func3' => ['wt' => 150],
        ];

        $result = new XHProfResult($testData);

        $this->assertSame($testData, $result->data);
    }

    public function testDataPropertyWithoutData(): void
    {
        $result = new XHProfResult();

        $this->assertNull($result->data);
    }

    public function testJsonSerializeWithoutData(): void
    {
        $result = new XHProfResult();
        $serialized = $result->jsonSerialize();

        $this->assertSame([], $serialized);
    }

    public function testJsonSerializeWithData(): void
    {
        $testData = ['func1' => ['wt' => 100]];
        $filePath = '/tmp/test.json';

        $result = new XHProfResult($testData, $filePath);
        $serialized = $result->jsonSerialize();

        $this->assertNotEmpty($serialized);
        $this->assertSame($filePath, $serialized['source']);
    }

    public function testStartStopLifecycleWhenXhprofNotAvailable(): void
    {
        if (function_exists('xhprof_enable')) {
            $this->markTestSkipped('XHProf is available, cannot test degradation');
        }

        $result = XHProfResult::start();
        $stopped = $result->stop('test://uri');

        $this->assertInstanceOf(XHProfResult::class, $result);
        $this->assertInstanceOf(XHProfResult::class, $stopped);
        $this->assertNull($stopped->data);
        $this->assertNull($stopped->filePath);
    }

    /** @requires extension xhprof */
    public function testStartStopLifecycleWhenXhprofAvailable(): void
    {
        if (! function_exists('xhprof_enable') || ! function_exists('xhprof_disable')) {
            $this->markTestSkipped('XHProf extension is not available');
        }

        $result = XHProfResult::start();
        $this->assertInstanceOf(XHProfResult::class, $result);

        // Do some work to generate profile data
        $dummy = 0;
        for ($i = 0; $i < 1000; $i++) {
            $dummy += $i;
        }

        $stopped = $result->stop('test://uri/with/path');

        $this->assertInstanceOf(XHProfResult::class, $stopped);

        // When XHProf is working, we should get data
        if ($stopped->data !== null) {
            $this->assertIsArray($stopped->data);
            $this->assertNotNull($stopped->filePath);
            $this->assertTrue(file_exists($stopped->filePath));

            // Verify file contains valid JSON
            $fileContent = file_get_contents($stopped->filePath);
            $this->assertIsString($fileContent);
            $decoded = json_decode($fileContent, true);
            $this->assertIsArray($decoded);
        }
    }

    public function testSaveToFileCreatesValidJsonFile(): void
    {
        $testData = [
            'main()' => ['wt' => 1000, 'ct' => 1],
            'test_function' => ['wt' => 500, 'ct' => 2],
        ];

        $result = new XHProfResult($testData, null);
        $stopped = $result->stop('test://save/file/test');

        $filePath = $stopped->filePath;
        if ($filePath !== null) {
            $this->assertTrue(file_exists($filePath));

            $content = file_get_contents($filePath);
            $this->assertIsString($content);
            $decoded = json_decode($content, true);

            $this->assertIsArray($decoded);
            $this->assertArrayHasKey('main()', $decoded);
            $this->assertArrayHasKey('test_function', $decoded);

            return;
        }

        // If no file was created (XHProf not available), verify graceful handling
        $this->assertTrue($filePath === null);
    }
}
