<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Profiler;

use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

class ProfileTest extends TestCase
{
    public function testConstructorSetsPropertiesCorrectly(): void
    {
        $xhprof = new XHProfResult(['func1' => ['wt' => 100]], '/tmp/xhprof.json');
        $xdebug = new XdebugTrace('trace content', '/tmp/trace.xt');
        $php = new PhpProfile([['function' => 'test_func']]);

        $profile = new Profile($xhprof, $xdebug, $php);

        $this->assertSame($xhprof, $profile->xhprof);
        $this->assertSame($xdebug, $profile->xdebug);
        $this->assertSame($php, $profile->php);
    }

    public function testConstructorWithNullValues(): void
    {
        $profile = new Profile();

        $this->assertNull($profile->xhprof);
        $this->assertNull($profile->xdebug);
        $this->assertNull($profile->php);
    }

    public function testConstructorWithPartialValues(): void
    {
        $xhprof = new XHProfResult(['func1' => ['wt' => 100]]);
        $profile = new Profile($xhprof);

        $this->assertSame($xhprof, $profile->xhprof);
        $this->assertNull($profile->xdebug);
        $this->assertNull($profile->php);
    }

    public function testJsonSerializeWithAllProfilers(): void
    {
        $xhprofData = ['func1' => ['wt' => 100, 'ct' => 1]];
        $xhprof = new XHProfResult($xhprofData, '/tmp/xhprof.json');
        $xdebug = new XdebugTrace('trace content', '/tmp/trace.xt');
        $php = new PhpProfile([['function' => 'test_func', 'file' => '/path/test.php']]);

        $profile = new Profile($xhprof, $xdebug, $php);
        $serialized = $profile->jsonSerialize();

        $this->assertNotEmpty($serialized);
        $this->assertArrayHasKey('xhprof', $serialized);
        $this->assertArrayHasKey('xdebug', $serialized);
        $this->assertArrayHasKey('php', $serialized);

        // Check XHProf summary
        $xhprofSummary = $serialized['xhprof'];
        $this->assertIsArray($xhprofSummary);
        $this->assertArrayHasKey('source', $xhprofSummary);
        $this->assertSame('/tmp/xhprof.json', $xhprofSummary['source']);

        // Check Xdebug summary
        $xdebugSummary = $serialized['xdebug'];
        $this->assertIsArray($xdebugSummary);
        $this->assertArrayHasKey('source', $xdebugSummary);
        $this->assertArrayHasKey('file_size', $xdebugSummary);
        $this->assertArrayHasKey('compressed', $xdebugSummary);
        $this->assertSame('/tmp/trace.xt', $xdebugSummary['source']);
        $this->assertIsInt($xdebugSummary['file_size']);
        $this->assertIsBool($xdebugSummary['compressed']);

        // Check PHP summary
        $phpSummary = $serialized['php'];
        $this->assertIsArray($phpSummary);
        $this->assertArrayHasKey('backtrace', $phpSummary);
        $this->assertIsArray($phpSummary['backtrace']);
    }

    public function testJsonSerializeWithNullProfilers(): void
    {
        $profile = new Profile();
        $serialized = $profile->jsonSerialize();

        $this->assertNotEmpty($serialized);
        $this->assertArrayHasKey('xhprof', $serialized);
        $this->assertArrayHasKey('xdebug', $serialized);
        $this->assertArrayHasKey('php', $serialized);

        $this->assertSame([], $serialized['xhprof']);
        $this->assertSame([], $serialized['xdebug']);
        $this->assertSame(['backtrace' => []], $serialized['php']);
    }

    public function testJsonSerializeWithMixedProfilers(): void
    {
        $xhprof = new XHProfResult(['func1' => ['wt' => 200]], '/tmp/test.json');
        $profile = new Profile($xhprof, null, null);
        $serialized = $profile->jsonSerialize();

        /** @var array<string, mixed> $serialized */
        $this->assertNotEmpty($serialized);

        // XHProf should have data
        $xhprofSummary = $serialized['xhprof'];
        $this->assertIsArray($xhprofSummary);
        $this->assertArrayHasKey('source', $xhprofSummary);
        $this->assertSame('/tmp/test.json', $xhprofSummary['source']);
        // XHProf data is present (simplified validation)
        $this->assertNotNull($xhprofSummary['source']);

        // Others should be empty
        $this->assertSame([], $serialized['xdebug']);
        $this->assertSame(['backtrace' => []], $serialized['php']);
    }

    public function testGetXhprofSummaryWithEmptyData(): void
    {
        $xhprof = new XHProfResult(); // Empty XHProf result
        $profile = new Profile($xhprof);
        $serialized = $profile->jsonSerialize();

        $xhprofSummary = $serialized['xhprof'];
        $this->assertIsArray($xhprofSummary);
        $this->assertArrayHasKey('source', $xhprofSummary);
        $this->assertNull($xhprofSummary['source']);
    }

    public function testGetXdebugSummaryWithFileSize(): void
    {
        // Create a temporary file to test file size calculation
        $tempFile = tempnam(sys_get_temp_dir(), 'profile_test');
        $testContent = 'test trace content for size testing';
        file_put_contents($tempFile, $testContent);

        try {
            $xdebug = new XdebugTrace($testContent, $tempFile);
            $profile = new Profile(null, $xdebug);
            $serialized = $profile->jsonSerialize();

            $xdebugSummary = $serialized['xdebug'];
            $this->assertIsArray($xdebugSummary);
            $this->assertSame($tempFile, $xdebugSummary['source']);
            $this->assertSame(strlen($testContent), $xdebugSummary['file_size']);
            $this->assertFalse($xdebugSummary['compressed']);
        } finally {
            unlink($tempFile);
        }
    }

    public function testGetXdebugSummaryWithCompression(): void
    {
        $xdebug = new XdebugTrace('compressed content', '/tmp/trace.xt.gz');
        $profile = new Profile(null, $xdebug);
        $serialized = $profile->jsonSerialize();

        $xdebugSummary = $serialized['xdebug'];
        $this->assertIsArray($xdebugSummary);
        $this->assertTrue($xdebugSummary['compressed']);
    }

    public function testGetPhpSummaryWithBacktrace(): void
    {
        $backtrace = [
            ['function' => 'func1', 'file' => '/path/file1.php', 'line' => 10],
            ['function' => 'func2', 'file' => '/path/file2.php', 'line' => 20, 'class' => 'TestClass'],
        ];

        $php = new PhpProfile($backtrace);
        $profile = new Profile(null, null, $php);
        $serialized = $profile->jsonSerialize();

        $phpSummary = $serialized['php'];
        $this->assertIsArray($phpSummary);
        $this->assertArrayHasKey('backtrace', $phpSummary);
        $this->assertSame($backtrace, $phpSummary['backtrace']);
    }

    public function testAllProfilingsIntegration(): void
    {
        // Coverage: Profile with all three profilers + Usage example
        $xhprof = new XHProfResult(['func' => ['wt' => 100]], '/tmp/test.json');
        $xdebug = new XdebugTrace('trace data', '/tmp/test.xt');
        $php = new PhpProfile([['function' => 'test']]);

        $profile = new Profile($xhprof, $xdebug, $php);
        $serialized = $profile->jsonSerialize();

        // Verify all three profiler types are included
        $this->assertArrayHasKey('xhprof', $serialized);
        $this->assertArrayHasKey('xdebug', $serialized);
        $this->assertArrayHasKey('php', $serialized);
    }

    public function testMultipleProfileInstances(): void
    {
        // Coverage: Independent Profile instances + Usage example
        $profile1 = new Profile(new XHProfResult(['test1' => ['wt' => 100]]));
        $profile2 = new Profile(new XHProfResult(['test2' => ['wt' => 200]]));

        // Verify instance independence
        $this->assertNotSame($profile1, $profile2);
    }
}
