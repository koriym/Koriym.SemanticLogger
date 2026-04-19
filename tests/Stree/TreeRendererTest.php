<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use PHPUnit\Framework\TestCase;

use function explode;
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
                'openId' => 'test_1',
            ],
            'events' => [],
        ];

        $renderer = new TreeRenderer();
        $config = new RenderConfig(false, 0.0, 5);

        $result = $renderer->render($logData, $config);

        $this->assertStringContainsString('session', $result);
        $this->assertStringContainsString('test_operation', $result);
        $this->assertStringContainsString('[5.0ms]', $result);
        $this->assertStringContainsString('└──', $result);
    }

    public function testSessionRootLine(): void
    {
        $logData = [
            'open' => [
                'id' => 'op_1',
                'type' => 'some_op',
                'schemaUrl' => 'test.json',
                'context' => ['executionTime' => 0.010],
            ],
            'close' => [
                'id' => 'close_1',
                'type' => 'close',
                'schemaUrl' => 'test.json',
                'context' => [],
                'openId' => 'op_1',
            ],
            'events' => [],
        ];

        $renderer = new TreeRenderer();
        $config = new RenderConfig(false, 0.0, 5);

        $result = $renderer->render($logData, $config);
        $lines = explode("\n", trim($result));

        // First line is session
        $this->assertStringStartsWith('session', $lines[0]);
        $this->assertStringContainsString('[10.0ms]', $lines[0]);
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
        $config = new RenderConfig(true, 0.0, 5);

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
                'openId' => 'operation_1',
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
        $config = new RenderConfig(true, 0.0, 5);

        $result = $renderer->render($logData, $config);

        $this->assertStringContainsString('test_operation', $result);
        $this->assertStringContainsString('test_event', $result);
        $this->assertStringContainsString('[3.0ms]', $result);
        $this->assertStringContainsString('[event]', $result);
    }

    public function testTimeThreshold(): void
    {
        $logData = [
            'open' => [
                'id' => 'parent_1',
                'type' => 'parent_operation',
                'schemaUrl' => 'test.json',
                'context' => ['executionTime' => 0.050], // 50ms - above threshold
                'open' => [
                    'id' => 'fast_1',
                    'type' => 'fast_operation',
                    'schemaUrl' => 'test.json',
                    'context' => ['executionTime' => 0.001], // 1ms - below threshold
                ],
            ],
            'close' => [
                'id' => 'close_1',
                'type' => 'close',
                'schemaUrl' => 'test.json',
                'context' => [],
            ],
            'events' => [
                [
                    'id' => 'slow_event',
                    'type' => 'slow_operation',
                    'schemaUrl' => 'test.json',
                    'context' => ['duration' => 0.020], // 20ms - above threshold
                    'openId' => 'parent_1',
                ],
            ],
        ];

        $renderer = new TreeRenderer();
        $config = new RenderConfig(true, 0.010, 5); // 10ms threshold

        $result = $renderer->render($logData, $config);

        // Root appears in session + as child (above threshold)
        $this->assertStringContainsString('parent_operation', $result);
        // fast_operation is below threshold - hidden
        $this->assertStringNotContainsString('fast_operation', $result);
        // slow_operation event is above threshold - shown
        $this->assertStringContainsString('slow_operation', $result);
    }

    public function testUnclosedStatusAnnotation(): void
    {
        $logData = [
            'open' => [
                'id' => 'op_1',
                'type' => 'some_operation',
                'schemaUrl' => 'test.json',
                'context' => [],
            ],
            'close' => null,
            'events' => [],
        ];

        $renderer = new TreeRenderer();
        $config = new RenderConfig(false, 0.0, 5);

        $result = $renderer->render($logData, $config);

        $this->assertStringContainsString(': unclosed', $result);
    }

    public function testFailedStatusAnnotation(): void
    {
        $logData = [
            'open' => [
                'id' => 'op_1',
                'type' => 'payment',
                'schemaUrl' => 'test.json',
                'context' => ['amount' => 100],
            ],
            'close' => [
                'id' => 'close_1',
                'type' => 'payment_close',
                'schemaUrl' => 'test.json',
                'context' => ['success' => false],
                'openId' => 'op_1',
            ],
            'events' => [],
        ];

        $renderer = new TreeRenderer();
        $config = new RenderConfig(false, 0.0, 5);

        $result = $renderer->render($logData, $config);

        $this->assertStringContainsString(': Failed', $result);
    }

    public function testStatusPropagatesUpward(): void
    {
        $logData = [
            'open' => [
                'id' => 'parent_1',
                'type' => 'checkout',
                'schemaUrl' => 'test.json',
                'context' => [],
                'open' => [
                    'id' => 'child_1',
                    'type' => 'charge',
                    'schemaUrl' => 'test.json',
                    'context' => ['amount' => 100],
                ],
            ],
            'close' => [
                'id' => 'parent_close',
                'type' => 'checkout_close',
                'schemaUrl' => 'test.json',
                'context' => [],
                'openId' => 'parent_1',
                'close' => [
                    'id' => 'child_close',
                    'type' => 'charge_close',
                    'schemaUrl' => 'test.json',
                    'context' => ['success' => false],
                    'openId' => 'child_1',
                ],
            ],
            'events' => [],
        ];

        $renderer = new TreeRenderer();
        $config = new RenderConfig(false, 0.0, 5);

        $result = $renderer->render($logData, $config);

        // Both session and checkout should show Failed
        $lines = explode("\n", trim($result));
        $this->assertStringContainsString(': Failed', $lines[0]); // session line
        $this->assertStringContainsString(': Failed', $result);   // at least one occurrence
    }

    public function testFullModeShowsContextLeaves(): void
    {
        $logData = [
            'open' => [
                'id' => 'op_1',
                'type' => 'some_op',
                'schemaUrl' => 'test.json',
                'context' => ['operation' => 'validate', 'input' => 'data'],
            ],
            'close' => [
                'id' => 'close_1',
                'type' => 'some_op_close',
                'schemaUrl' => 'test.json',
                'context' => ['result' => 'ok'],
                'openId' => 'op_1',
            ],
            'events' => [],
        ];

        $renderer = new TreeRenderer();
        $config = new RenderConfig(true, 0.0, 5);

        $result = $renderer->render($logData, $config);

        $this->assertStringContainsString('operation: validate', $result);
        $this->assertStringContainsString('input: data', $result);
        // Close-only key shows with → prefix
        $this->assertStringContainsString('→', $result);
        $this->assertStringContainsString('result: ok', $result);
    }

    public function testGenericUnknownTypeRenders(): void
    {
        $logData = [
            'open' => [
                'id' => 'meta_1',
                'type' => 'metamorphosis_open',
                'schemaUrl' => 'test.json',
                'context' => [
                    'fromClass' => 'Be\\Skeleton\\Input\\HelloInput',
                    'beAttribute' => '#[Be(Be\\Skeleton\\Final\\Hello::class)]',
                ],
            ],
            'close' => [
                'id' => 'meta_close_1',
                'type' => 'metamorphosis_close',
                'schemaUrl' => 'test.json',
                'context' => ['finalClass' => 'Be\\Skeleton\\Final\\Hello'],
                'openId' => 'meta_1',
            ],
            'events' => [],
        ];

        $renderer = new TreeRenderer();
        $config = new RenderConfig(false, 0.0, 5);

        $result = $renderer->render($logData, $config);

        // Type shown (suffix stripped because closed)
        $this->assertStringContainsString('metamorphosis', $result);
        // FQCN shortened
        $this->assertStringContainsString('fromClass=HelloInput', $result);
        // Not empty - context visible
        $this->assertStringContainsString('beAttribute=', $result);
    }
}
