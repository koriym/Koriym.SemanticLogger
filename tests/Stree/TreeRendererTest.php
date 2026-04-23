<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use Koriym\SemanticLogger\Stree\Fake\FakeFormatter;
use PHPUnit\Framework\TestCase;

use function explode;
use function str_starts_with;
use function trim;

final class TreeRendererTest extends TestCase
{
    public function testBasicRendering(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'test_1',
                    'type' => 'test_operation',
                    'schemaUrl' => 'test.json',
                    'context' => ['executionTime' => 0.005],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'openId' => 'test_1',
                ],
            ],
            'events' => [],
        ];

        $renderer = new TreeRenderer();
        $config = new RenderConfig(false, 0.0, 5);

        $result = $renderer->render($logData, $config);

        $this->assertStringNotContainsString('session', $result);
        $this->assertStringContainsString('test_operation', $result);
        $this->assertStringContainsString('[5.0ms]', $result);
    }

    public function testRootNodeRendersFlushLeft(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'op_1',
                    'type' => 'some_op',
                    'schemaUrl' => 'test.json',
                    'context' => ['executionTime' => 0.010],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'openId' => 'op_1',
                ],
            ],
            'events' => [],
        ];

        $renderer = new TreeRenderer();
        $config = new RenderConfig(false, 0.0, 5);

        $result = $renderer->render($logData, $config);
        $lines = explode("\n", trim($result));

        // First line is the root node itself, flush-left (no tree prefix, no "session" header)
        $this->assertStringStartsWith('some_op', $lines[0]);
        $this->assertStringContainsString('[10.0ms]', $lines[0]);
    }

    public function testMultipleRootsRenderAsFlushLeftSiblings(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'a_1',
                    'type' => 'first_op',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
                [
                    'id' => 'b_1',
                    'type' => 'second_op',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
            ],
            'close' => [],
            'events' => [],
        ];

        $renderer = new TreeRenderer();
        $config = new RenderConfig(false, 0.0, 5);

        $result = $renderer->render($logData, $config);
        $lines = explode("\n", trim($result));

        $this->assertSame('first_op : unclosed', $lines[0]);
        $this->assertSame('second_op : unclosed', $lines[1]);
        $this->assertFalse(str_starts_with($lines[0], '├'));
        $this->assertFalse(str_starts_with($lines[1], '└'));
    }

    public function testOrphanCloseRendersAsTopLevelDiagnosticInSingleRootLog(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'op_1',
                    'type' => 'some_open',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'some_close',
                    'schemaUrl' => 'test.json',
                    'context' => ['result' => 'ok'],
                    'openId' => 'missing_1',
                ],
            ],
            'events' => [],
        ];

        $renderer = new TreeRenderer();
        $config = new RenderConfig(false, 0.0, 5);

        $result = $renderer->render($logData, $config);
        $lines = explode("\n", trim($result));

        $this->assertSame('some_open : unclosed', $lines[0]);
        $this->assertSame('some_close result=ok [orphan close]', $lines[1]);
        $this->assertFalse(str_starts_with($lines[1], '└'));
    }

    public function testTopLevelCloseWithoutOpenIdRemainsTopLevelDiagnosticInSingleRootLog(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'op_1',
                    'type' => 'some_open',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'some_close',
                    'schemaUrl' => 'test.json',
                    'context' => ['result' => 'ok'],
                ],
            ],
            'events' => [],
        ];

        $renderer = new TreeRenderer();
        $config = new RenderConfig(false, 0.0, 5);

        $result = $renderer->render($logData, $config);
        $lines = explode("\n", trim($result));

        $this->assertSame('some_open : unclosed', $lines[0]);
        $this->assertSame('some_close result=ok [orphan close]', $lines[1]);
        $this->assertFalse(str_starts_with($lines[1], '└'));
    }

    public function testNestedRendering(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'parent_1',
                    'type' => 'parent_operation',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'open' => [
                        [
                            'id' => 'child_1',
                            'type' => 'child_operation',
                            'schemaUrl' => 'test.json',
                            'context' => [],
                        ],
                    ],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
            ],
            'events' => [],
        ];

        $renderer = new TreeRenderer();
        $config = new RenderConfig(true, 0.0, 5);

        $result = $renderer->render($logData, $config);

        $this->assertStringContainsString('parent_operation', $result);
        $this->assertStringContainsString('child_operation', $result);
        $this->assertStringContainsString('── child_operation', $result);
    }

    public function testEventsRendering(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'operation_1',
                    'type' => 'test_operation',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'openId' => 'operation_1',
                ],
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
                [
                    'id' => 'parent_1',
                    'type' => 'parent_operation',
                    'schemaUrl' => 'test.json',
                    'context' => ['executionTime' => 0.050], // 50ms - above threshold
                    'open' => [
                        [
                            'id' => 'fast_1',
                            'type' => 'fast_operation',
                            'schemaUrl' => 'test.json',
                            'context' => ['executionTime' => 0.001], // 1ms - below threshold
                        ],
                    ],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
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
                [
                    'id' => 'op_1',
                    'type' => 'some_operation',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
            ],
            'close' => [],
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
                [
                    'id' => 'op_1',
                    'type' => 'payment',
                    'schemaUrl' => 'test.json',
                    'context' => ['amount' => 100],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'payment_close',
                    'schemaUrl' => 'test.json',
                    'context' => ['success' => false],
                    'openId' => 'op_1',
                ],
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
                [
                    'id' => 'parent_1',
                    'type' => 'checkout',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'open' => [
                        [
                            'id' => 'child_1',
                            'type' => 'charge',
                            'schemaUrl' => 'test.json',
                            'context' => ['amount' => 100],
                        ],
                    ],
                ],
            ],
            'close' => [
                [
                    'id' => 'parent_close',
                    'type' => 'checkout_close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'openId' => 'parent_1',
                    'close' => [
                        [
                            'id' => 'child_close',
                            'type' => 'charge_close',
                            'schemaUrl' => 'test.json',
                            'context' => ['success' => false],
                            'openId' => 'child_1',
                        ],
                    ],
                ],
            ],
            'events' => [],
        ];

        $renderer = new TreeRenderer();
        $config = new RenderConfig(false, 0.0, 5);

        $result = $renderer->render($logData, $config);

        // Root node (checkout) propagates the Failed status from its child (charge).
        $lines = explode("\n", trim($result));
        $this->assertStringStartsWith('checkout', $lines[0]);
        $this->assertStringContainsString(': Failed', $lines[0]);
    }

    public function testFullModeShowsContextLeaves(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'op_1',
                    'type' => 'some_op',
                    'schemaUrl' => 'test.json',
                    'context' => ['operation' => 'validate', 'input' => 'data'],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'some_op_close',
                    'schemaUrl' => 'test.json',
                    'context' => ['result' => 'ok'],
                    'openId' => 'op_1',
                ],
            ],
            'events' => [],
        ];

        $renderer = new TreeRenderer();
        $config = new RenderConfig(true, 0.0, 5);

        $result = $renderer->render($logData, $config);

        $this->assertStringContainsString('operation: validate', $result);
        $this->assertStringContainsString('input: data', $result);
        $this->assertStringContainsString('└── close', $result);
        $this->assertStringContainsString('result: ok', $result);
    }

    public function testFullModeShowsProfileUnderCloseBranch(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'op_1',
                    'type' => 'becoming_open',
                    'schemaUrl' => 'test.json',
                    'context' => [
                        'input' => 'Be\\Skeleton\\Input\\HelloInput',
                        'prop' => ['name' => 'World'],
                    ],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'becoming_close',
                    'schemaUrl' => 'test.json',
                    'context' => [
                        'exit' => 'success',
                        'final' => 'Be\\Skeleton\\Final\\Hello',
                    ],
                    'profile' => [
                        'wallTime' => 0.012,
                        'xdebugTrace' => [
                            ['path' => '/tmp/profile_a.xt'],
                            ['path' => '/tmp/profile_b.xt'],
                        ],
                    ],
                    'openId' => 'op_1',
                ],
            ],
            'events' => [],
        ];

        $renderer = new TreeRenderer();
        $config = new RenderConfig(true, 0.0, 5);

        $result = $renderer->render($logData, $config);

        $this->assertStringContainsString('becoming HelloInput', $result);
        $this->assertStringContainsString('name: World', $result);
        $this->assertStringContainsString('└── close', $result);
        $this->assertStringContainsString('exit: success', $result);
        $this->assertStringNotContainsString('final: Be\\Skeleton\\Final\\Hello', $result);
        $this->assertStringContainsString('profile', $result);
        $this->assertStringContainsString('wallTime: 0.012', $result);
        $this->assertStringContainsString('xdebugTrace[0].path: /tmp/profile_a.xt', $result);
        $this->assertStringContainsString('xdebugTrace[1].path: /tmp/profile_b.xt', $result);
    }

    public function testFullModeSemanticTreeSuppressesInputSourcesAndFlattensProps(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'becoming_1',
                    'type' => 'becoming_open',
                    'schemaUrl' => 'test.json',
                    'context' => [
                        'input' => 'Be\\Skeleton\\Input\\HelloInput',
                        'prop' => ['name' => 'World'],
                    ],
                    'open' => [
                        [
                            'id' => 'being_final_1',
                            'type' => 'being_final_open',
                            'schemaUrl' => 'test.json',
                            'context' => [
                                'from' => 'Be\\Skeleton\\Input\\HelloInput',
                                'final' => 'Be\\Skeleton\\Final\\Hello',
                                'input' => ['name' => 'Be\\Skeleton\\Input\\HelloInput::name'],
                                'inject' => ['greeting' => 'Be\\Skeleton\\Reason\\Greeting'],
                            ],
                            'close' => [
                                'id' => 'being_final_close_1',
                                'type' => 'being_final_close',
                                'schemaUrl' => 'test.json',
                                'context' => [
                                    'final' => 'Be\\Skeleton\\Final\\Hello',
                                    'prop' => ['greeting' => 'Hello World'],
                                ],
                                'openId' => 'being_final_1',
                            ],
                        ],
                    ],
                ],
            ],
            'close' => [
                [
                    'id' => 'becoming_close_1',
                    'type' => 'becoming_close',
                    'schemaUrl' => 'test.json',
                    'context' => [
                        'exit' => 'success',
                        'final' => 'Be\\Skeleton\\Final\\Hello',
                    ],
                    'openId' => 'becoming_1',
                ],
            ],
            'events' => [],
        ];

        $renderer = new TreeRenderer();
        $config = new RenderConfig(true, 0.0, 5);

        $result = $renderer->render($logData, $config);

        $this->assertStringContainsString('becoming HelloInput', $result);
        $this->assertStringContainsString('name: World', $result);
        $this->assertStringContainsString('being_final Hello input=[name] inject=[greeting]', $result);
        $this->assertStringContainsString('inject.greeting: Be\\Skeleton\\Reason\\Greeting', $result);
        $this->assertStringContainsString('greeting: Hello World', $result);
        $this->assertStringContainsString('exit: success', $result);
        $this->assertStringNotContainsString('from: Be\\Skeleton\\Input\\HelloInput', $result);
        $this->assertStringNotContainsString('input.name: Be\\Skeleton\\Input\\HelloInput::name', $result);
        $this->assertStringNotContainsString('final: Be\\Skeleton\\Final\\Hello', $result);
    }

    public function testGenericUnknownTypeRenders(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'meta_1',
                    'type' => 'metamorphosis_open',
                    'schemaUrl' => 'test.json',
                    'context' => [
                        'fromClass' => 'Be\\Skeleton\\Input\\HelloInput',
                        'beAttribute' => '#[Be(Be\\Skeleton\\Final\\Hello::class)]',
                    ],
                ],
            ],
            'close' => [
                [
                    'id' => 'meta_close_1',
                    'type' => 'metamorphosis_close',
                    'schemaUrl' => 'test.json',
                    'context' => ['finalClass' => 'Be\\Skeleton\\Final\\Hello'],
                    'openId' => 'meta_1',
                ],
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

    public function testRegisteredFormatterRendersAtRootFlushLeft(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'op_1',
                    'type' => 'fake_open',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                ],
            ],
            'close' => [
                [
                    'id' => 'close_1',
                    'type' => 'fake_close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'openId' => 'op_1',
                ],
            ],
            'events' => [],
        ];

        $registry = new FormatterRegistry();
        $registry->register('fake_open', new FakeFormatter());
        $renderer = new TreeRenderer();
        $config = new RenderConfig(false, 0.0, 5, false, $registry);

        $result = $renderer->render($logData, $config);
        $lines = explode("\n", trim($result));

        // Root line is the formatter's open line, flush-left.
        $this->assertSame('fake open=fake_open', $lines[0]);
        // Continuation line sits directly under the root, also flush-left (no tree prefix for root children).
        $this->assertSame('└── close=fake_close', $lines[1]);
    }

    public function testCompactClosePrefersExitOverFinal(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'becoming_1',
                    'type' => 'becoming',
                    'schemaUrl' => 'test.json',
                    'context' => ['input' => 'App\\Input\\HelloInput'],
                ],
            ],
            'close' => [
                [
                    'id' => 'becoming_close_1',
                    'type' => 'becoming_close',
                    'schemaUrl' => 'test.json',
                    'context' => [
                        'exit' => 'success',
                        'final' => 'App\\Final\\Hello',
                    ],
                    'openId' => 'becoming_1',
                ],
            ],
            'events' => [],
        ];

        $renderer = new TreeRenderer();
        $config = new RenderConfig(false, 0.0, 5);

        $result = $renderer->render($logData, $config);

        $this->assertStringContainsString('└── exit=success', $result);
        $this->assertStringNotContainsString('final=Hello', $result);
    }

    public function testRegisteredFormatterMultilineContinuationIndentsUnderChildPrefix(): void
    {
        $logData = [
            'open' => [
                [
                    'id' => 'root_1',
                    'type' => 'container',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'open' => [
                        [
                            'id' => 'child_1',
                            'type' => 'fake_open',
                            'schemaUrl' => 'test.json',
                            'context' => [],
                        ],
                    ],
                ],
            ],
            'close' => [
                [
                    'id' => 'root_close',
                    'type' => 'container_close',
                    'schemaUrl' => 'test.json',
                    'context' => [],
                    'openId' => 'root_1',
                    'close' => [
                        [
                            'id' => 'child_close',
                            'type' => 'fake_close',
                            'schemaUrl' => 'test.json',
                            'context' => [],
                            'openId' => 'child_1',
                        ],
                    ],
                ],
            ],
            'events' => [],
        ];

        $registry = new FormatterRegistry();
        $registry->register('fake_open', new FakeFormatter());
        $renderer = new TreeRenderer();
        $config = new RenderConfig(false, 0.0, 5, false, $registry);

        $result = $renderer->render($logData, $config);
        $lines = explode("\n", trim($result));

        // Child node rendered under a tree prefix; continuation indents to child-content column.
        $this->assertStringContainsString('└── fake open=fake_open', $lines[1]);
        $this->assertStringContainsString('    └── close=fake_close', $lines[2]);
    }
}
