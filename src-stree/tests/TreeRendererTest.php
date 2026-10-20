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
    /**
     * @param array<string, mixed>       $context
     * @param list<array<string, mixed>> $children
     * @param array<string, mixed>|null  $close
     *
     * @return array<string, mixed>
     */
    private static function openEntry(string $id, string $type, array $context = [], array $children = [], array|null $close = null): array
    {
        $entry = [
            'kind' => 'open',
            'id' => $id,
            'type' => $type,
            'schemaUrl' => 'test.json',
            'context' => $context,
            'children' => $children,
        ];

        return $close === null ? $entry : $entry + ['close' => $close];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private static function eventEntry(string $id, string $type, array $context = []): array
    {
        return [
            'kind' => 'event',
            'id' => $id,
            'type' => $type,
            'schemaUrl' => 'test.json',
            'context' => $context,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    private static function closeEntry(string $id, string $type, array $context = [], array $profile = []): array
    {
        $close = [
            'id' => $id,
            'type' => $type,
            'schemaUrl' => 'test.json',
            'context' => $context,
        ];

        return $profile === [] ? $close : $close + ['profile' => $profile];
    }

    public function testBasicRendering(): void
    {
        $logData = [
            'entries' => [
                self::openEntry('test_1', 'test_operation', ['executionTime' => 0.005], [], self::closeEntry('close_1', 'close')),
            ],
        ];

        $config = new RenderConfig(false, 0.0, 5);
        $renderer = new TreeRenderer($config);

        $result = $renderer->renderTree($logData);

        $this->assertStringNotContainsString('session', $result);
        $this->assertStringContainsString('test_operation', $result);
        $this->assertStringContainsString('[5.0ms]', $result);
    }

    public function testRootNodeRendersFlushLeft(): void
    {
        $logData = [
            'entries' => [
                self::openEntry('op_1', 'some_op', ['executionTime' => 0.010], [], self::closeEntry('close_1', 'close')),
            ],
        ];

        $config = new RenderConfig(false, 0.0, 5);
        $renderer = new TreeRenderer($config);

        $result = $renderer->renderTree($logData);
        $lines = explode("\n", trim($result));

        // First line is the root node itself, flush-left (no tree prefix, no "session" header)
        $this->assertStringStartsWith('some_op', $lines[0]);
        $this->assertStringContainsString('[10.0ms]', $lines[0]);
    }

    public function testMultipleRootsRenderAsFlushLeftSiblings(): void
    {
        $logData = [
            'entries' => [
                self::openEntry('a_1', 'first_op'),
                self::openEntry('b_1', 'second_op'),
            ],
        ];

        $config = new RenderConfig(false, 0.0, 5);
        $renderer = new TreeRenderer($config);

        $result = $renderer->renderTree($logData);
        $lines = explode("\n", trim($result));

        $this->assertSame('first_op : unclosed', $lines[0]);
        $this->assertSame('second_op : unclosed', $lines[1]);
        $this->assertFalse(str_starts_with($lines[0], '├'));
        $this->assertFalse(str_starts_with($lines[1], '└'));
    }

    public function testEventOnlyLogRendersTopLevelEvent(): void
    {
        $config = new RenderConfig(false, 0.0, 5);
        $renderer = new TreeRenderer($config);

        $result = $renderer->renderTree([
            'entries' => [self::eventEntry('notice_1', 'notice', ['message' => 'event only'])],
        ]);

        $this->assertSame('notice message=event only [event]', trim($result));
    }

    public function testRootEventsAndOpensKeepRecordOrder(): void
    {
        $renderer = new TreeRenderer(new RenderConfig(false, 0.0, 5));

        $result = $renderer->renderTree([
            'entries' => [
                self::eventEntry('notice_1', 'notice'),
                self::openEntry('op_1', 'some_op'),
                self::eventEntry('notice_2', 'late_notice'),
            ],
        ]);

        $this->assertSame(
            ['notice [event]', 'some_op : unclosed', 'late_notice [event]'],
            explode("\n", trim($result)),
        );
    }

    public function testOrphanCloseRendersAsTopLevelDiagnosticInSingleRootLog(): void
    {
        $logData = [
            'entries' => [self::openEntry('op_1', 'some_open')],
            'close' => [
                self::closeEntry('close_1', 'some_close', ['result' => 'ok']) +
        ['openId' => 'missing_1'],
            ],
        ];

        $config = new RenderConfig(false, 0.0, 5);
        $renderer = new TreeRenderer($config);

        $result = $renderer->renderTree($logData);
        $lines = explode("\n", trim($result));

        $this->assertSame('some_open : unclosed', $lines[0]);
        $this->assertSame('some_close result=ok [orphan close]', $lines[1]);
        $this->assertFalse(str_starts_with($lines[1], '└'));
    }

    public function testTopLevelCloseWithoutOpenIdRemainsTopLevelDiagnosticInSingleRootLog(): void
    {
        $logData = [
            'entries' => [self::openEntry('op_1', 'some_open')],
            'close' => [self::closeEntry('close_1', 'some_close', ['result' => 'ok'])],
        ];

        $config = new RenderConfig(false, 0.0, 5);
        $renderer = new TreeRenderer($config);

        $result = $renderer->renderTree($logData);
        $lines = explode("\n", trim($result));

        $this->assertSame('some_open : unclosed', $lines[0]);
        $this->assertSame('some_close result=ok [orphan close]', $lines[1]);
        $this->assertFalse(str_starts_with($lines[1], '└'));
    }

    public function testNestedRendering(): void
    {
        $logData = [
            'entries' => [
                self::openEntry(
                    'parent_1',
                    'parent_operation',
                    [],
                    [self::openEntry('child_1', 'child_operation')],
                    self::closeEntry('close_1', 'close'),
                ),
            ],
        ];

        $config = new RenderConfig(true, 0.0, 5);
        $renderer = new TreeRenderer($config);

        $result = $renderer->renderTree($logData);

        $this->assertStringContainsString('parent_operation', $result);
        $this->assertStringContainsString('child_operation', $result);
        $this->assertStringContainsString('── child_operation', $result);
    }

    public function testEventsRendering(): void
    {
        $logData = [
            'entries' => [
                self::openEntry(
                    'operation_1',
                    'test_operation',
                    [],
                    [self::eventEntry('event_1', 'test_event', ['duration' => 0.003])],
                    self::closeEntry('close_1', 'close'),
                ),
            ],
        ];

        $config = new RenderConfig(true, 0.0, 5);
        $renderer = new TreeRenderer($config);

        $result = $renderer->renderTree($logData);

        $this->assertStringContainsString('test_operation', $result);
        $this->assertStringContainsString('test_event', $result);
        $this->assertStringContainsString('[3.0ms]', $result);
        $this->assertStringContainsString('[event]', $result);
    }

    public function testChildrenRenderInRecordOrder(): void
    {
        $logData = [
            'entries' => [
                self::openEntry('parent_1', 'parent_op', [], [
                    self::eventEntry('event_1', 'first_event'),
                    self::openEntry('child_1', 'middle_op'),
                    self::eventEntry('event_2', 'last_event'),
                ]),
            ],
        ];

        $renderer = new TreeRenderer(new RenderConfig(false, 0.0, 5));

        $lines = explode("\n", trim($renderer->renderTree($logData)));

        $this->assertSame('parent_op : unclosed', $lines[0]);
        $this->assertSame('├── first_event [event]', $lines[1]);
        $this->assertSame('├── middle_op : unclosed', $lines[2]);
        $this->assertSame('└── last_event [event]', $lines[3]);
    }

    public function testTimeThreshold(): void
    {
        $logData = [
            'entries' => [
                self::openEntry(
                    'parent_1',
                    'parent_operation',
                    ['executionTime' => 0.050], // 50ms - above threshold
                    [
                        self::openEntry('fast_1', 'fast_operation', ['executionTime' => 0.001]), // 1ms - below threshold
                        self::eventEntry('slow_event', 'slow_operation', ['duration' => 0.020]), // 20ms - above threshold
                    ],
                    self::closeEntry('close_1', 'close'),
                ),
            ],
        ];

        $config = new RenderConfig(true, 0.010, 5);
        $renderer = new TreeRenderer($config); // 10ms threshold

        $result = $renderer->renderTree($logData);

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
            'entries' => [self::openEntry('op_1', 'some_operation')],
        ];

        $config = new RenderConfig(false, 0.0, 5);
        $renderer = new TreeRenderer($config);

        $result = $renderer->renderTree($logData);

        $this->assertStringContainsString(': unclosed', $result);
    }

    public function testFailedStatusAnnotation(): void
    {
        $logData = [
            'entries' => [
                self::openEntry(
                    'op_1',
                    'payment',
                    ['amount' => 100],
                    [],
                    self::closeEntry('close_1', 'payment_close', ['success' => false]),
                ),
            ],
        ];

        $config = new RenderConfig(false, 0.0, 5);
        $renderer = new TreeRenderer($config);

        $result = $renderer->renderTree($logData);

        $this->assertStringContainsString(': Failed', $result);
    }

    public function testStatusPropagatesUpward(): void
    {
        $logData = [
            'entries' => [
                self::openEntry(
                    'parent_1',
                    'checkout',
                    [],
                    [
                        self::openEntry(
                            'child_1',
                            'charge',
                            ['amount' => 100],
                            [],
                            self::closeEntry('child_close', 'charge_close', ['success' => false]),
                        ),
                    ],
                    self::closeEntry('parent_close', 'checkout_close'),
                ),
            ],
        ];

        $config = new RenderConfig(false, 0.0, 5);
        $renderer = new TreeRenderer($config);

        $result = $renderer->renderTree($logData);

        // Root node (checkout) propagates the Failed status from its child (charge).
        $lines = explode("\n", trim($result));
        $this->assertStringStartsWith('checkout', $lines[0]);
        $this->assertStringContainsString(': Failed', $lines[0]);
    }

    public function testFullModeShowsContextLeaves(): void
    {
        $logData = [
            'entries' => [
                self::openEntry(
                    'op_1',
                    'some_op',
                    ['operation' => 'validate', 'input' => 'data'],
                    [],
                    self::closeEntry('close_1', 'some_op_close', ['result' => 'ok']),
                ),
            ],
        ];

        $config = new RenderConfig(true, 0.0, 5);
        $renderer = new TreeRenderer($config);

        $result = $renderer->renderTree($logData);

        $this->assertStringContainsString('operation: validate', $result);
        $this->assertStringContainsString('input: data', $result);
        $this->assertStringContainsString('└── close some_op_close', $result);
        $this->assertStringContainsString('result: ok', $result);
    }

    public function testFullModeShowsProfileUnderCloseBranch(): void
    {
        $logData = [
            'entries' => [
                self::openEntry(
                    'op_1',
                    'becoming_open',
                    [
                        'input' => 'Be\\Skeleton\\Input\\HelloInput',
                        'prop' => ['name' => 'World'],
                    ],
                    [],
                    self::closeEntry(
                        'close_1',
                        'becoming_close',
                        [
                            'exit' => 'success',
                            'final' => 'Be\\Skeleton\\Final\\Hello',
                        ],
                        [
                            'wallTime' => 0.012,
                            'xdebugTrace' => [
                                ['path' => '/tmp/profile_a.xt'],
                                ['path' => '/tmp/profile_b.xt'],
                            ],
                        ],
                    ),
                ),
            ],
        ];

        $config = new RenderConfig(true, 0.0, 5);
        $renderer = new TreeRenderer($config);

        $result = $renderer->renderTree($logData);

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
            'entries' => [
                self::openEntry(
                    'becoming_1',
                    'becoming_open',
                    [
                        'input' => 'Be\\Skeleton\\Input\\HelloInput',
                        'prop' => ['name' => 'World'],
                    ],
                    [
                        self::openEntry(
                            'being_final_1',
                            'being_final_open',
                            [
                                'from' => 'Be\\Skeleton\\Input\\HelloInput',
                                'final' => 'Be\\Skeleton\\Final\\Hello',
                                'input' => ['name' => 'Be\\Skeleton\\Input\\HelloInput::name'],
                                'inject' => ['greeting' => 'Be\\Skeleton\\Reason\\Greeting'],
                            ],
                            [],
                            self::closeEntry('being_final_close_1', 'being_final_close', [
                                'final' => 'Be\\Skeleton\\Final\\Hello',
                                'prop' => ['greeting' => 'Hello World'],
                            ]),
                        ),
                    ],
                    self::closeEntry('becoming_close_1', 'becoming_close', [
                        'exit' => 'success',
                        'final' => 'Be\\Skeleton\\Final\\Hello',
                    ]),
                ),
            ],
        ];

        $config = new RenderConfig(true, 0.0, 5);
        $renderer = new TreeRenderer($config);

        $result = $renderer->renderTree($logData);

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
            'entries' => [
                self::openEntry(
                    'meta_1',
                    'metamorphosis_open',
                    [
                        'fromClass' => 'Be\\Skeleton\\Input\\HelloInput',
                        'beAttribute' => '#[Be(Be\\Skeleton\\Final\\Hello::class)]',
                    ],
                    [],
                    self::closeEntry('meta_close_1', 'metamorphosis_close', ['finalClass' => 'Be\\Skeleton\\Final\\Hello']),
                ),
            ],
        ];

        $config = new RenderConfig(false, 0.0, 5);
        $renderer = new TreeRenderer($config);

        $result = $renderer->renderTree($logData);

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
            'entries' => [
                self::openEntry('op_1', 'fake_open', [], [], self::closeEntry('close_1', 'fake_close')),
            ],
        ];

        $registry = new FormatterRegistry();
        $registry->register('fake_open', new FakeFormatter());
        $config = new RenderConfig(false, 0.0, 5, false, $registry);
        $renderer = new TreeRenderer($config);

        $result = $renderer->renderTree($logData);
        $lines = explode("\n", trim($result));

        // Root line is the formatter's open line, flush-left.
        $this->assertSame('fake open=fake_open', $lines[0]);
        // Continuation line sits directly under the root, also flush-left (no tree prefix for root children).
        $this->assertSame('└── close=fake_close', $lines[1]);
    }

    public function testCompactClosePrefersExitOverFinal(): void
    {
        $logData = [
            'entries' => [
                self::openEntry(
                    'becoming_1',
                    'becoming',
                    ['input' => 'App\\Input\\HelloInput'],
                    [],
                    self::closeEntry('becoming_close_1', 'becoming_close', [
                        'exit' => 'success',
                        'final' => 'App\\Final\\Hello',
                    ]),
                ),
            ],
        ];

        $config = new RenderConfig(false, 0.0, 5);
        $renderer = new TreeRenderer($config);

        $result = $renderer->renderTree($logData);

        $this->assertStringContainsString('└── becoming_close exit=success', $result);
        $this->assertStringNotContainsString('final=Hello', $result);
    }

    public function testCompactCloseLineLeadsWithCloseType(): void
    {
        $logData = [
            'entries' => [
                self::openEntry(
                    'get_1',
                    'get',
                    ['uri' => 'page://self/dep/parent'],
                    [
                        self::openEntry(
                            'get_2',
                            'get',
                            ['uri' => 'page://self/dep/child-c'],
                            [],
                            self::closeEntry('close_2', 'cache_hit', ['layer' => 'resource']),
                        ),
                    ],
                    self::closeEntry('close_1', 'cache_miss', ['layer' => 'resource']),
                ),
            ],
        ];

        $renderer = new TreeRenderer(new RenderConfig(false, 0.0, 5));

        $lines = explode("\n", trim($renderer->renderTree($logData)));

        $this->assertSame('get uri=page://self/dep/parent', $lines[0]);
        $this->assertSame('├── get uri=page://self/dep/child-c', $lines[1]);
        $this->assertSame('│   └── cache_hit layer=resource', $lines[2]);
        $this->assertSame('└── cache_miss layer=resource', $lines[3]);
    }

    public function testCompactCloseLineIsTypeOnlyWhenCloseAddsNoSignal(): void
    {
        $logData = [
            'entries' => [
                self::openEntry(
                    'op_1',
                    'some_op',
                    ['operation' => 'validate'],
                    [],
                    self::closeEntry('close_1', 'some_op_close', ['operation' => 'validate']),
                ),
            ],
        ];

        $renderer = new TreeRenderer(new RenderConfig(false, 0.0, 5));

        $lines = explode("\n", trim($renderer->renderTree($logData)));

        $this->assertSame('some_op operation=validate', $lines[0]);
        $this->assertSame('└── some_op_close', $lines[1]);
    }

    public function testNodeWithoutCloseGetsNoCloseLine(): void
    {
        $logData = [
            'entries' => [self::openEntry('op_1', 'some_op', ['operation' => 'validate'])],
        ];

        $renderer = new TreeRenderer(new RenderConfig(false, 0.0, 5));

        $this->assertSame('some_op operation=validate : unclosed', trim($renderer->renderTree($logData)));
    }

    public function testTimeThresholdHidesSkippedNodeCloseType(): void
    {
        $logData = [
            'entries' => [
                self::openEntry(
                    'slow_1',
                    'slow_op',
                    ['executionTime' => 0.050],
                    [
                        self::openEntry(
                            'fast_1',
                            'fast_op',
                            ['executionTime' => 0.001],
                            [],
                            self::closeEntry('close_2', 'cache_hit', ['layer' => 'resource']),
                        ),
                    ],
                    self::closeEntry('close_1', 'cache_miss', ['layer' => 'resource']),
                ),
            ],
        ];

        $renderer = new TreeRenderer(new RenderConfig(false, 0.010, 5));

        $result = $renderer->renderTree($logData);

        $this->assertStringNotContainsString('cache_hit', $result);
        $this->assertStringContainsString('└── cache_miss layer=resource', $result);
    }

    public function testFullModeCloseBranchCarriesCloseType(): void
    {
        $logData = [
            'entries' => [
                self::openEntry(
                    'get_1',
                    'get',
                    ['uri' => 'page://self/dep/child-c'],
                    [],
                    self::closeEntry('close_1', 'cache_hit', ['layer' => 'resource']),
                ),
            ],
        ];

        $renderer = new TreeRenderer(new RenderConfig(true, 0.0, 5));

        $lines = explode("\n", trim($renderer->renderTree($logData)));

        $this->assertSame('get uri=page://self/dep/child-c', $lines[0]);
        $this->assertSame('├── uri: page://self/dep/child-c', $lines[1]);
        $this->assertSame('└── close cache_hit', $lines[2]);
        $this->assertSame('    └── layer: resource', $lines[3]);
    }

    public function testRegisteredFormatterMultilineContinuationIndentsUnderChildPrefix(): void
    {
        $logData = [
            'entries' => [
                self::openEntry(
                    'root_1',
                    'container',
                    [],
                    [self::openEntry('child_1', 'fake_open', [], [], self::closeEntry('child_close', 'fake_close'))],
                    self::closeEntry('root_close', 'container_close'),
                ),
            ],
        ];

        $registry = new FormatterRegistry();
        $registry->register('fake_open', new FakeFormatter());
        $config = new RenderConfig(false, 0.0, 5, false, $registry);
        $renderer = new TreeRenderer($config);

        $result = $renderer->renderTree($logData);
        $lines = explode("\n", trim($result));

        // Child is no longer last (the root's close line is), and the formatter
        // continuation indents to the child-content column.
        $this->assertSame('├── fake open=fake_open', $lines[1]);
        $this->assertSame('│   └── close=fake_close', $lines[2]);
        // Close lines carry their type: the child's first, then the root's.
        $this->assertSame('│   └── fake_close', $lines[3]);
        $this->assertSame('└── container_close', $lines[4]);
    }
}
