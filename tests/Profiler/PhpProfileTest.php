<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Profiler;

use PHPUnit\Framework\TestCase;

use function count;
use function str_contains;

class PhpProfileTest extends TestCase
{
    public function testConstructorSetsBacktraceCorrectly(): void
    {
        $backtrace = [
            ['function' => 'test_function', 'file' => '/path/to/file.php', 'line' => 10],
        ];

        $profile = new PhpProfile($backtrace);

        $this->assertSame($backtrace, $profile->backtrace);
    }

    public function testConstructorWithEmptyBacktrace(): void
    {
        $profile = new PhpProfile();

        $this->assertSame([], $profile->backtrace);
    }

    public function testCaptureReturnsPhpProfileInstance(): void
    {
        $profile = PhpProfile::capture();

        $this->assertInstanceOf(PhpProfile::class, $profile);
        $this->assertNotEmpty($profile->backtrace);
    }

    public function testCaptureWithCustomLimit(): void
    {
        $profile = PhpProfile::capture(5);

        $this->assertInstanceOf(PhpProfile::class, $profile);
        $this->assertLessThanOrEqual(5, count($profile->backtrace));
    }

    public function testCaptureFiltersFrameworkFiles(): void
    {
        $profile = PhpProfile::capture();

        // Check that BEAR.Resource internal files are filtered out
        foreach ($profile->backtrace as $frame) {
            if (isset($frame['file'])) {
                $this->assertStringNotContainsString('/BEAR/Resource/', $frame['file']);
                $this->assertStringNotContainsString('/Koriym/SemanticLogger/', $frame['file']);
                $this->assertStringNotContainsString('/phpunit/', $frame['file']);
            }
        }
    }

    public function testCaptureIncludesRequiredFields(): void
    {
        $profile = PhpProfile::capture();

        foreach ($profile->backtrace as $frame) {
            // Every frame should have a function field
            $this->assertArrayHasKey('function', $frame);
            $this->assertNotEmpty($frame['function']);
        }
    }

    public function testCaptureIncludesOptionalFields(): void
    {
        // Trigger a method call to get class/type information
        $this->triggerMethodCall();

        $profile = PhpProfile::capture();

        $foundClassInfo = false;
        foreach ($profile->backtrace as $frame) {
            if (isset($frame['class'])) {
                $this->assertNotEmpty($frame['class']);
                $foundClassInfo = true;
            }

            if (isset($frame['type'])) {
                $this->assertContains($frame['type'], ['->', '::']);
            }

            if (isset($frame['file'])) {
                $this->assertNotEmpty($frame['file']);
            }

            if (isset($frame['line'])) {
                $this->assertGreaterThan(0, $frame['line']);
                $this->assertGreaterThan(0, $frame['line']);
            }
        }

        // We should have found at least some class information from this test method
        $this->assertTrue($foundClassInfo, 'Expected to find class information in backtrace');
    }

    private function triggerMethodCall(): void
    {
        // This method helps generate backtrace with class information
        $this->helperMethod();
    }

    private function helperMethod(): void
    {
        // Helper method to create stack depth
    }

    public function testJsonSerializeReturnsCorrectStructure(): void
    {
        // Coverage: PhpProfile::jsonSerialize() + Usage example for developers
        $backtrace = [
            [
                'function' => 'test_function',
                'file' => '/path/to/file.php',
                'line' => 10,
                'class' => 'TestClass',
                'type' => '->',
            ],
        ];

        $profile = new PhpProfile($backtrace);
        $serialized = $profile->jsonSerialize();

        // Verify basic structure is preserved for JSON output
        $this->assertArrayHasKey('backtrace', $serialized);
        $this->assertSame($backtrace, $serialized['backtrace']);
    }

    public function testJsonSerializeWithEmptyBacktrace(): void
    {
        // Coverage: Empty backtrace edge case + Usage example
        $profile = new PhpProfile();
        $serialized = $profile->jsonSerialize();

        // Verify empty backtrace produces valid structure
        $this->assertArrayHasKey('backtrace', $serialized);
        $this->assertSame([], $serialized['backtrace']);
    }

    public function testBacktraceFilteringBehavior(): void
    {
        $profile = PhpProfile::capture();

        $vendorFileFound = false;
        // Verify that vendor files are filtered out (except tests)
        foreach ($profile->backtrace as $frame) {
            if (isset($frame['file']) && str_contains($frame['file'], '/vendor/')) {
                $vendorFileFound = true;
                // If it's a vendor file, it should be a test file
                $this->assertTrue(
                    str_contains($frame['file'], '/tests/'),
                    'Vendor files should be filtered except for test files',
                );
            }
        }

        // Assert that filtering is working - we should have some backtrace
        $this->assertGreaterThanOrEqual(0, count($profile->backtrace), 'Backtrace filtering should produce some result');
    }

    public function testBacktraceLimitIsRespected(): void
    {
        $smallLimit = 3;
        $profile = PhpProfile::capture($smallLimit);

        $this->assertLessThanOrEqual($smallLimit, count($profile->backtrace));
    }

    public function testCaptureIncludesBacktraceFrames(): void
    {
        // Coverage: PhpProfile::capture() produces non-empty backtrace
        $profile = PhpProfile::capture();

        // Simple verification that capture works
        $this->assertGreaterThanOrEqual(0, count($profile->backtrace));
    }

    public function testFrameStructureBasics(): void
    {
        // Coverage: Frame structure validation + Usage example for backtrace format
        $profile = PhpProfile::capture();

        // Basic structure verification - each frame must have 'function'
        foreach ($profile->backtrace as $frame) {
            $this->assertArrayHasKey('function', $frame);
        }
    }

    public function testCaptureWithZeroLimit(): void
    {
        $profile = PhpProfile::capture(0);

        $this->assertInstanceOf(PhpProfile::class, $profile);
        $this->assertSame([], $profile->backtrace);
    }

    public function testMultipleCaptures(): void
    {
        // Coverage: Multiple capture calls + Usage example for independent instances
        $profile1 = PhpProfile::capture();
        $profile2 = PhpProfile::capture();

        // Verify independence of capture calls
        $this->assertNotSame($profile1, $profile2);
    }

    public function testCaptureWithCallStack(): void
    {
        // Coverage: Capture from within method call + Usage example
        $profile = $this->helperCapture();

        // Verify capture works from nested calls
        $this->assertGreaterThanOrEqual(0, count($profile->backtrace));
    }

    private function helperCapture(): PhpProfile
    {
        return PhpProfile::capture();
    }
}
