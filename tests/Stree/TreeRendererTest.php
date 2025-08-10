<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use PHPUnit\Framework\TestCase;

use function trim;

final class TreeRendererTest extends TestCase
{
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
                'id' => 'close_1',
                'type' => 'close',
                'schemaUrl' => 'test.json',
                'context' => [],
            ],
            'events' => [],
        ];

        $renderer = new TreeRenderer();
        $config = new RenderConfig(2, [], 0.0, false, 5);

        $result = $renderer->render($logData, $config);

        $this->assertStringContainsString('test_operation', $result);
        $this->assertStringContainsString('[5.0ms]', $result);
        $this->assertStringContainsString('└──', $result);
    }

    public function testNestedRendering(): void
    {
        $logData = [
            'open' => [
                'id' => 'parent_1',
                'type' => 'parent_operation',
                'schemaUrl' => 'test.json',
                'context' => [],
                'open' => [
                    'id' => 'child_1',
                    'type' => 'child_operation',
                    'schemaUrl' => 'test.json',
                    'context' => [],
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

        $renderer = new TreeRenderer();
        $config = new RenderConfig(10, [], 0.0, true, 5);

        $result = $renderer->render($logData, $config);

        $this->assertStringContainsString('parent_operation', $result);
        $this->assertStringContainsString('child_operation', $result);
        $this->assertStringContainsString('└──', $result);
    }

    public function testEventsRendering(): void
    {
        $logData = [
            'open' => [
                'id' => 'operation_1',
                'type' => 'test_operation',
                'schemaUrl' => 'test.json',
                'context' => [],
            ],
            'close' => [
                'id' => 'close_1',
                'type' => 'close',
                'schemaUrl' => 'test.json',
                'context' => [],
            ],
            'events' => [
                [
                    'id' => 'event_1',
                    'type' => 'test_event',
                    'schemaUrl' => 'test.json',
                    'context' => ['duration' => 0.003],
                    'openId' => 'operation_1',
                ],
            ],
        ];

        $renderer = new TreeRenderer();
        $config = new RenderConfig(10, [], 0.0, true, 5);

        $result = $renderer->render($logData, $config);

        $this->assertStringContainsString('test_operation', $result);
        $this->assertStringContainsString('test_event', $result);
        $this->assertStringContainsString('[3.0ms]', $result);
    }

    public function testDepthLimiting(): void
    {
        $logData = [
            'open' => [
                'id' => 'level1_1',
                'type' => 'level1',
                'schemaUrl' => 'test.json',
                'context' => [],
                'open' => [
                    'id' => 'level2_1',
                    'type' => 'level2',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'open' => [
                        'id' => 'level3_1',
                        'type' => 'level3',
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

        $renderer = new TreeRenderer();
        $config = new RenderConfig(2, [], 0.0, false, 5);

        $result = $renderer->render($logData, $config);

        $this->assertStringContainsString('level1', $result);
        $this->assertStringContainsString('level2', $result);
        $this->assertStringContainsString('level3 [...]', $result);
    }

    public function testExpandTypes(): void
    {
        $logData = [
            'open' => [
                'id' => 'level1_1',
                'type' => 'level1',
                'schemaUrl' => 'test.json',
                'context' => [],
                'open' => [
                    'id' => 'special_1',
                    'type' => 'special_type',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'open' => [
                        'id' => 'level3_1',
                        'type' => 'level3',
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

        $renderer = new TreeRenderer();
        $config = new RenderConfig(2, ['special_type'], 0.0, false, 5);

        $result = $renderer->render($logData, $config);

        $this->assertStringContainsString('level1', $result);
        $this->assertStringContainsString('special_type', $result);
        $this->assertStringContainsString('level3', $result);
    }

    public function testTimeThreshold(): void
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

        $renderer = new TreeRenderer();
        $config = new RenderConfig(10, [], 0.010, true, 5); // 10ms threshold

        $result = $renderer->render($logData, $config);

        $this->assertStringNotContainsString('fast_operation', $result);
        $this->assertEmpty(trim($result)); // No output because parent is filtered out
    }
}
