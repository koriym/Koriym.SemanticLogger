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
}
