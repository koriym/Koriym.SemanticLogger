<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use PHPUnit\Framework\TestCase;

use function file_exists;
use function file_put_contents;
use function json_encode;
use function ob_get_clean;
use function ob_start;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;

final class StreeCommandTest extends TestCase
{
    private string $tempFile = '';

    protected function tearDown(): void
    {
        if ($this->tempFile !== '' && file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function testShowHelp(): void
    {
        $command = new StreeCommand();
        $exitCode = $command->run(['stree', '--help']);

        $this->assertSame(0, $exitCode);
    }

    public function testMissingFile(): void
    {
        $command = new StreeCommand();
        $exitCode = $command->run(['stree']);

        $this->assertSame(1, $exitCode);
    }

    public function testNonExistentFile(): void
    {
        $command = new StreeCommand();
        $exitCode = $command->run(['stree', 'nonexistent.json']);

        $this->assertSame(1, $exitCode);
    }

    public function testInvalidJson(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'stree_test_');
        file_put_contents($this->tempFile, 'invalid json');

        $command = new StreeCommand();
        $exitCode = $command->run(['stree', $this->tempFile]);

        $this->assertSame(1, $exitCode);
    }

    public function testBasicRendering(): void
    {
        $logData = [
            'open' => [
                'id' => 'test_1',
                'type' => 'test_operation',
                'schemaUrl' => 'test.json',
                'context' => ['executionTime' => 0.005],
            ],
            'close' => [
                'id' => 'test_close_1',
                'type' => 'test_close',
                'schemaUrl' => 'test.json',
                'context' => [],
            ],
            'events' => [],
        ];

        $this->tempFile = tempnam(sys_get_temp_dir(), 'stree_test_');
        file_put_contents($this->tempFile, json_encode($logData, JSON_THROW_ON_ERROR));

        $command = new StreeCommand();

        ob_start();
        $exitCode = $command->run(['stree', $this->tempFile]);
        $output = ob_get_clean() ?: '';

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('test_operation', $output);
        $this->assertStringContainsString('[5.0ms]', $output);
    }

    public function testDepthOption(): void
    {
        $logData = [
            'open' => [
                'id' => 'parent_1',
                'type' => 'parent',
                'schemaUrl' => 'test.json',
                'context' => [],
                'open' => [
                    'id' => 'child_1',
                    'type' => 'child',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'open' => [
                        'id' => 'grandchild_1',
                        'type' => 'grandchild',
                        'schemaUrl' => 'test.json',
                        'context' => [],
                    ],
                ],
            ],
            'close' => [
                'id' => 'close_1',
                'type' => 'close',
                'schemaUrl' => 'test.json',
                'context' => [],
            ],
            'events' => [],
        ];

        $this->tempFile = tempnam(sys_get_temp_dir(), 'stree_test_');
        file_put_contents($this->tempFile, json_encode($logData, JSON_THROW_ON_ERROR));

        $command = new StreeCommand();

        // Test depth=1
        ob_start();
        $exitCode = $command->run(['stree', '--depth=1', $this->tempFile]);
        $output = ob_get_clean() ?: '';

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('parent', $output);
        $this->assertStringContainsString('[...]', $output);
        $this->assertStringNotContainsString('grandchild', $output);
    }

    public function testFullOption(): void
    {
        $logData = [
            'open' => [
                'id' => 'parent_1',
                'type' => 'parent',
                'schemaUrl' => 'test.json',
                'context' => [],
                'open' => [
                    'id' => 'child_1',
                    'type' => 'child',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'open' => [
                        'id' => 'grandchild_1',
                        'type' => 'grandchild',
                        'schemaUrl' => 'test.json',
                        'context' => [],
                    ],
                ],
            ],
            'close' => [
                'id' => 'close_1',
                'type' => 'close',
                'schemaUrl' => 'test.json',
                'context' => [],
            ],
            'events' => [],
        ];

        $this->tempFile = tempnam(sys_get_temp_dir(), 'stree_test_');
        file_put_contents($this->tempFile, json_encode($logData, JSON_THROW_ON_ERROR));

        $command = new StreeCommand();

        ob_start();
        $exitCode = $command->run(['stree', '--full', $this->tempFile]);
        $output = ob_get_clean() ?: '';

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('parent', $output);
        $this->assertStringContainsString('child', $output);
        $this->assertStringContainsString('grandchild', $output);
    }

    public function testExpandOption(): void
    {
        $logData = [
            'open' => [
                'id' => 'parent_1',
                'type' => 'parent',
                'schemaUrl' => 'test.json',
                'context' => [],
                'open' => [
                    'id' => 'special_1',
                    'type' => 'special_type',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'open' => [
                        'id' => 'deep_1',
                        'type' => 'deep_type',
                        'schemaUrl' => 'test.json',
                        'context' => [],
                    ],
                ],
            ],
            'close' => [
                'id' => 'close_1',
                'type' => 'close',
                'schemaUrl' => 'test.json',
                'context' => [],
            ],
            'events' => [],
        ];

        $this->tempFile = tempnam(sys_get_temp_dir(), 'stree_test_');
        file_put_contents($this->tempFile, json_encode($logData, JSON_THROW_ON_ERROR));

        $command = new StreeCommand();

        ob_start();
        $exitCode = $command->run(['stree', '--depth=2', '--expand=special_type', $this->tempFile]);
        $output = ob_get_clean() ?: '';

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('special_type', $output);
        $this->assertStringContainsString('deep_type', $output);
    }

    public function testThresholdOption(): void
    {
        $logData = [
            'open' => [
                'id' => 'fast_1',
                'type' => 'fast_operation',
                'schemaUrl' => 'test.json',
                'context' => ['executionTime' => 0.001], // 1ms
                'open' => [
                    'id' => 'slow_1',
                    'type' => 'slow_operation',
                    'schemaUrl' => 'test.json',
                    'context' => ['executionTime' => 0.020], // 20ms
                ],
            ],
            'close' => [
                'id' => 'close_1',
                'type' => 'close',
                'schemaUrl' => 'test.json',
                'context' => [],
            ],
            'events' => [],
        ];

        $this->tempFile = tempnam(sys_get_temp_dir(), 'stree_test_');
        file_put_contents($this->tempFile, json_encode($logData, JSON_THROW_ON_ERROR));

        $command = new StreeCommand();

        ob_start();
        $exitCode = $command->run(['stree', '--threshold=10ms', '--full', $this->tempFile]);
        $output = ob_get_clean() ?: '';

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('fast_operation', $output);
    }

    public function testShortOptions(): void
    {
        $logData = [
            'open' => ['id' => 'test_1', 'type' => 'test', 'schemaUrl' => 'test.json', 'context' => []],
            'close' => ['id' => 'close_1', 'type' => 'close', 'schemaUrl' => 'test.json', 'context' => []],
            'events' => [],
        ];

        $this->tempFile = tempnam(sys_get_temp_dir(), 'stree_test_');
        file_put_contents($this->tempFile, json_encode($logData));

        $command = new StreeCommand();

        // Test -h (help)
        $exitCode = $command->run(['stree', '-h']);
        $this->assertSame(0, $exitCode);

        // Test -f (full)
        ob_start();
        $exitCode = $command->run(['stree', '-f', $this->tempFile]);
        ob_get_clean();
        $this->assertSame(0, $exitCode);

        // Test -d (depth)
        ob_start();
        $exitCode = $command->run(['stree', '-d', '3', $this->tempFile]);
        ob_get_clean();
        $this->assertSame(0, $exitCode);

        // Test -e (expand)
        ob_start();
        $exitCode = $command->run(['stree', '-e', 'database', $this->tempFile]);
        ob_get_clean();
        $this->assertSame(0, $exitCode);

        // Test -t (threshold)
        ob_start();
        $exitCode = $command->run(['stree', '-t', '10ms', $this->tempFile]);
        ob_get_clean();
        $this->assertSame(0, $exitCode);

        // Test -l (lines)
        ob_start();
        $exitCode = $command->run(['stree', '-l', '10', $this->tempFile]);
        ob_get_clean();
        $this->assertSame(0, $exitCode);
    }

    public function testFormatOptions(): void
    {
        $logData = [
            'open' => ['id' => 'test_1', 'type' => 'test', 'schemaUrl' => 'test.json', 'context' => []],
            'close' => ['id' => 'close_1', 'type' => 'close', 'schemaUrl' => 'test.json', 'context' => []],
            'events' => [],
        ];

        $this->tempFile = tempnam(sys_get_temp_dir(), 'stree_test_');
        file_put_contents($this->tempFile, json_encode($logData));

        $command = new StreeCommand();

        // Test --format=html
        ob_start();
        $exitCode = $command->run(['stree', '--format=html', $this->tempFile]);
        $output = ob_get_clean() ?: '';
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('<!DOCTYPE html>', $output);

        // Test --format text (separate argument)
        ob_start();
        $exitCode = $command->run(['stree', '--format', 'text', $this->tempFile]);
        ob_get_clean();
        $this->assertSame(0, $exitCode);
    }

    public function testThresholdParsing(): void
    {
        $logData = [
            'open' => ['id' => 'test_1', 'type' => 'test', 'schemaUrl' => 'test.json', 'context' => ['executionTime' => 0.5]],
            'close' => ['id' => 'close_1', 'type' => 'close', 'schemaUrl' => 'test.json', 'context' => []],
            'events' => [],
        ];

        $this->tempFile = tempnam(sys_get_temp_dir(), 'stree_test_');
        file_put_contents($this->tempFile, json_encode($logData));

        $command = new StreeCommand();

        // Test milliseconds
        ob_start();
        $exitCode = $command->run(['stree', '--threshold=100ms', $this->tempFile]);
        ob_get_clean();
        $this->assertSame(0, $exitCode);

        // Test seconds
        ob_start();
        $exitCode = $command->run(['stree', '--threshold=0.1s', $this->tempFile]);
        ob_get_clean();
        $this->assertSame(0, $exitCode);

        // Test plain number (treated as seconds)
        ob_start();
        $exitCode = $command->run(['stree', '--threshold=0.1', $this->tempFile]);
        ob_get_clean();
        $this->assertSame(0, $exitCode);
    }

    public function testMultipleExpand(): void
    {
        $logData = [
            'open' => ['id' => 'test_1', 'type' => 'test', 'schemaUrl' => 'test.json', 'context' => []],
            'close' => ['id' => 'close_1', 'type' => 'close', 'schemaUrl' => 'test.json', 'context' => []],
            'events' => [],
        ];

        $this->tempFile = tempnam(sys_get_temp_dir(), 'stree_test_');
        file_put_contents($this->tempFile, json_encode($logData));

        $command = new StreeCommand();

        // Test multiple --expand options
        ob_start();
        $exitCode = $command->run(['stree', '-e', 'database', '-e', 'api', '--expand=cache', $this->tempFile]);
        ob_get_clean();
        $this->assertSame(0, $exitCode);
    }

    public function testOptionValidationErrors(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'stree_test_');
        file_put_contents($this->tempFile, '{}');

        $command = new StreeCommand();

        // Test missing depth value
        $exitCode = $command->run(['stree', '--depth']);
        $this->assertSame(1, $exitCode);

        // Test invalid depth format
        $exitCode = $command->run(['stree', '--depth=abc', $this->tempFile]);
        $this->assertSame(1, $exitCode);

        // Test negative depth
        $exitCode = $command->run(['stree', '-d', '-1', $this->tempFile]);
        $this->assertSame(1, $exitCode);

        // Test missing expand value
        $exitCode = $command->run(['stree', '--expand']);
        $this->assertSame(1, $exitCode);

        // Test empty expand value
        $exitCode = $command->run(['stree', '--expand=', $this->tempFile]);
        $this->assertSame(1, $exitCode);

        // Test missing threshold value
        $exitCode = $command->run(['stree', '--threshold']);
        $this->assertSame(1, $exitCode);

        // Test invalid threshold format
        $exitCode = $command->run(['stree', '--threshold=abc', $this->tempFile]);
        $this->assertSame(1, $exitCode);

        // Test invalid threshold format (bad ms)
        $exitCode = $command->run(['stree', '--threshold=abcms', $this->tempFile]);
        $this->assertSame(1, $exitCode);

        // Test invalid threshold format (bad s)
        $exitCode = $command->run(['stree', '--threshold=abcs', $this->tempFile]);
        $this->assertSame(1, $exitCode);

        // Test negative threshold
        $exitCode = $command->run(['stree', '--threshold=-1ms', $this->tempFile]);
        $this->assertSame(1, $exitCode);

        // Test missing lines value
        $exitCode = $command->run(['stree', '--lines']);
        $this->assertSame(1, $exitCode);

        // Test invalid lines format
        $exitCode = $command->run(['stree', '--lines=abc', $this->tempFile]);
        $this->assertSame(1, $exitCode);

        // Test negative lines
        $exitCode = $command->run(['stree', '-l', '-1', $this->tempFile]);
        $this->assertSame(1, $exitCode);

        // Test missing format value
        $exitCode = $command->run(['stree', '--format']);
        $this->assertSame(1, $exitCode);

        // Test invalid format value
        $exitCode = $command->run(['stree', '--format=xml', $this->tempFile]);
        $this->assertSame(1, $exitCode);

        // Test unknown option
        $exitCode = $command->run(['stree', '--unknown', $this->tempFile]);
        $this->assertSame(1, $exitCode);
    }

    public function testFileReadErrors(): void
    {
        $command = new StreeCommand();

        // Create file and make it unreadable (skip on Windows where this doesn't work reliably)
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->tempFile = tempnam(sys_get_temp_dir(), 'stree_test_');
            file_put_contents($this->tempFile, '{}');
            chmod($this->tempFile, 0000);
            
            $exitCode = $command->run(['stree', $this->tempFile]);
            $this->assertSame(1, $exitCode);
            
            // Restore permissions for cleanup
            chmod($this->tempFile, 0644);
        }
    }
}
