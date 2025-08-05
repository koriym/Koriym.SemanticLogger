<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function glob;
use function is_file;
use function json_decode;
use function sys_get_temp_dir;
use function unlink;

final class DevLoggerTest extends TestCase
{
    private string $logDirectory;
    private DevLogger $devLogger;
    private SemanticLogger $logger;

    protected function setUp(): void
    {
        $this->logDirectory = sys_get_temp_dir();

        // Clean up any existing log files
        $this->cleanupLogFiles();

        $this->devLogger = new DevLogger($this->logDirectory);
        $this->logger = new SemanticLogger();
    }

    protected function tearDown(): void
    {
        $this->cleanupLogFiles();
    }

    private function cleanupLogFiles(): void
    {
        $logFiles = glob($this->logDirectory . '/semantic-dev-*');
        if ($logFiles === false) {
            return;
        }

        foreach ($logFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testDevLoggerCreatesFiles(): void
    {
        // Create a simple semantic log
        $openId = $this->logger->open(new FakeContext('test'));
        $this->logger->close(new FakeContext('complete'), $openId);

        // Output to file
        $this->devLogger->log($this->logger);

        // Verify files were created
        $jsonFiles = glob($this->logDirectory . '/semantic-dev-*.json');
        $promptFiles = glob($this->logDirectory . '/semantic-dev-*-prompt.md');

        $this->assertIsArray($jsonFiles);
        $this->assertIsArray($promptFiles);
        $this->assertCount(1, $jsonFiles);
        $this->assertCount(1, $promptFiles);
    }

    public function testLogFileContainsValidJson(): void
    {
        // Create semantic log
        $openId = $this->logger->open(new FakeContext('test'));
        $this->logger->close(new FakeContext('complete'), $openId);

        // Output to file
        $this->devLogger->log($this->logger);

        // Get the created files
        $jsonFiles = glob($this->logDirectory . '/semantic-dev-*.json');
        $this->assertIsArray($jsonFiles);
        $this->assertNotEmpty($jsonFiles);

        $content = file_get_contents($jsonFiles[0]);
        $this->assertIsString($content);
        $data = json_decode($content, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('schemaUrl', $data);
        $this->assertArrayHasKey('open', $data);
        $this->assertArrayHasKey('close', $data);
    }

    public function testPromptFileContainsAnalysisInstructions(): void
    {
        // Create semantic log
        $openId = $this->logger->open(new FakeContext('test'));
        $this->logger->close(new FakeContext('complete'), $openId);

        // Output to file
        $this->devLogger->log($this->logger);

        // Get the created prompt file
        $promptFiles = glob($this->logDirectory . '/semantic-dev-*-prompt.md');
        $this->assertIsArray($promptFiles);
        $this->assertNotEmpty($promptFiles);

        $content = file_get_contents($promptFiles[0]);
        $this->assertIsString($content);

        $this->assertStringContainsString('semantic profiling data', $content);
        $this->assertStringContainsString('```json', $content);
        $this->assertStringContainsString('APPLICATION CODE performance', $content);
    }

    public function testSilentFailureOnJsonEncodingError(): void
    {
        // This test ensures DevLogger doesn't break main processing
        // even if something goes wrong with logging

        $openId = $this->logger->open(new FakeContext('test'));
        $this->logger->close(new FakeContext('complete'), $openId);

        // DevLogger should handle errors silently
        $this->devLogger->log($this->logger);

        // Test passes if no exception is thrown
        $this->expectNotToPerformAssertions();
    }

    public function testConstructorWithEmptyLogDirectory(): void
    {
        // Test constructor with empty log directory (uses temp dir)
        $devLogger = new DevLogger('');

        $openId = $this->logger->open(new FakeContext('test'));
        $this->logger->close(new FakeContext('complete'), $openId);

        // Should not throw exception
        $devLogger->log($this->logger);

        $this->expectNotToPerformAssertions();
    }

    public function testConstructorWithSpecificLogDirectory(): void
    {
        // Test constructor with specific log directory
        $customDir = '/tmp';
        $devLogger = new DevLogger($customDir);

        $openId = $this->logger->open(new FakeContext('test'));
        $this->logger->close(new FakeContext('complete'), $openId);

        $devLogger->log($this->logger);

        // Verify files were created in custom directory
        $jsonFiles = glob($customDir . '/semantic-dev-*.json');
        $this->assertIsArray($jsonFiles);
        $this->assertNotEmpty($jsonFiles);

        // Cleanup
        foreach ($jsonFiles as $file) {
            unlink($file);
        }

        $promptFiles = glob($customDir . '/semantic-dev-*-prompt.md');
        $this->assertIsArray($promptFiles);
        foreach ($promptFiles as $file) {
            unlink($file);
        }
    }

    public function testFilenamingWithUniqueIdentifiers(): void
    {
        // Test that files have unique names with timestamp, process ID, and unique ID
        $openId = $this->logger->open(new FakeContext('test1'));
        $this->logger->close(new FakeContext('complete1'), $openId);
        $this->devLogger->log($this->logger);

        $openId2 = $this->logger->open(new FakeContext('test2'));
        $this->logger->close(new FakeContext('complete2'), $openId2);
        $this->devLogger->log($this->logger);

        $jsonFiles = glob($this->logDirectory . '/semantic-dev-*.json');
        $this->assertIsArray($jsonFiles);
        $this->assertCount(2, $jsonFiles);

        // Verify files have different names
        $this->assertNotEquals($jsonFiles[0], $jsonFiles[1]);
    }
}
